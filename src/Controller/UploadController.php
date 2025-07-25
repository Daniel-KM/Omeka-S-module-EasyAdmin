<?php declare(strict_types=1);

namespace EasyAdmin\Controller;

use FilesystemIterator;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Stdlib\ErrorStore;

class UploadController extends AbstractActionController
{
    use TraitEasyDir;

    const STATUS_SUCCESS = 'success';
    const STATUS_ERROR = 'error';

    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var \Omeka\File\Validator
     */
    protected $validator;

    /**
     * @var bool
     */
    protected $allowAnyPath;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $tempDir;

    public function __construct(
        TempFileFactory $tempFileFactory,
        Validator $validator,
        bool $allowAnyPath,
        string $basePath,
        string $tempDir
    ) {
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->allowAnyPath = $allowAnyPath;
        $this->basePath = $basePath;
        $this->tempDir = $tempDir;
    }

    /**
     * Save a file locally for future ingesting.
     *
     * For each chunk, Flow.js sends a get with a query, then a post with the
     * same query but with the chunk too as file. It allows to resume broken
     * downloads without restarting.
     *
     * @see \Flow\Basic::save()
     */
    public function indexAction()
    {
        // TODO Fix issue with session warning appended to json (currently fixed via js): Warning: session_write_close(): Failed to write session data using user defined save handler.

        // Some security checks.

        $user = $this->identity();
        if (!$user) {
            return $this->jsonError(
                $this->translate('User not authenticated.'), // @translate
                Response::STATUS_CODE_403
            );
        }

        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $headers = $request->getHeaders()->toArray();

        /** @var \Laminas\View\Helper\ServerUrl $serverUrl */
        $serverUrl = $this->viewHelpers()->get('ServerUrl');
        if ($serverUrl->getHost() !== ($headers['Host'] ?? null)) {
            return $this->jsonError(
                $this->translate('The request must originate from the server.'), // @translate
                Response::STATUS_CODE_403
            );
        }

        // Check csrf for security.
        $form = $this->getForm(\Omeka\Form\ResourceForm::class);
        $form->setData(['csrf' => $headers['X-Csrf'] ?? null]);
        if (!$form->isValid()) {
            return $this->jsonError(
                $this->translate('Expired, invalid or missing CSRF token.'), // @translate
                Response::STATUS_CODE_403
            );
        }

        // Processing the chunk.

        $flowConfig = new \Flow\Config([
            'tempDir' => $this->tempDir,
        ]);

        // $chunk = $this->params()->fromQuery() ?: $this->params()->fromPost();
        $flowRequest = new \Flow\Request();

        $uploadFileName = 'omk'
            . '_' . (new \DateTime())->format('Ymd-His')
            . '_' . substr(strtr(base64_encode(random_bytes(128)), ['+' => '', '/' => '', '=' => '']), 0, 8)
            . '_' . $flowRequest->getFileName();
        $destination = $this->tempDir . DIRECTORY_SEPARATOR . $uploadFileName;

        try {
            $file = new \Flow\File($flowConfig, $flowRequest);
            if ($request->isGet()) {
                if ($file->checkChunk()) {
                    // Nothing to do here.
                } else {
                    // The 204 response MUST NOT include a message-body, and
                    // thus is always terminated by the first empty line after
                    // the header fields.
                    $this->getResponse()
                        ->setStatusCode(Response::STATUS_CODE_204);
                    // Don't use view model, there is no template.
                    return (new JsonModel())
                        ->setTerminal(true);
                }
            } else {
                if ($file->validateChunk()) {
                    $file->saveChunk();
                } else {
                    // Error, invalid chunk upload request, retry.
                    $this->getResponse()
                        ->setStatusCode(Response::STATUS_CODE_400);
                    // Don't use view model, there is no template.
                    return (new JsonModel())
                        ->setTerminal(true);
                }
            }
        } catch (\Exception $e) {
            $this->logger()->err($e);
            return (new JsonModel())
                ->setTerminal(true);
        }

        // Check if this is the last chunk.
        if (!$file->validateFile() || !$file->save($destination)) {
            return (new JsonModel())
                ->setTerminal(true);
        }

        // File is saved successfully and can be accessed at destination.

        // Ingester checks.

        $allowEmptyFiles = (bool) $this->settings()->get('easyadmin_allow_empty_files', false);
        $filesize = filesize($destination);
        if (!$filesize && !$allowEmptyFiles) {
            return $this->jsonError(
                $this->translate('The file is empty.'), // @translate
                Response::STATUS_CODE_412
            );
        }

        $filename = (string) $flowRequest->getFileName();
        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            return $this->jsonError(
                $this->translate($errorMessage),
                Response::STATUS_CODE_412
            );
        }

        $filepath = $destination;
        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($filename);
        $tempFile->setTempPath($filepath);

        $validateFile = (bool) $this->settings()->get('disable_file_validation', false);
        if ($validateFile) {
            $errorStore = new ErrorStore();
            if (!$this->validator->validate($tempFile, $errorStore)) {
                @unlink($filepath);
                $errors = $errorStore->getErrors();
                $errors = reset($errors);
                $error = is_array($errors) ? reset($errors) : $errors;
                return $this->jsonError(
                    $this->translate($error),
                    Response::STATUS_CODE_415
                );
            }
        }

        $errorMessage = null;
        $isFilenameValid = $this->checkFilename($filename, $errorMessage);
        if (!$isFilenameValid) {
            return $this->jsonError(
                $this->translate($errorMessage),
                Response::STATUS_CODE_415
            );
        }

        $isBulkUploadForm = !empty($headers['X-Is-Bulk-Upload-Form']);
        $result = null;
        if ($isBulkUploadForm) {
            $errorMessage = null;
            $dirPath = $this->getAndCheckDirPath(null, $errorMessage);
            if (!$dirPath) {
                return $this->jsonError($this->translate($errorMessage), Response::STATUS_CODE_500);
            }
            $newDestination = rtrim($dirPath, '//') . '/' . $filename;
            $fileExists = file_exists($newDestination);
            if ($fileExists) {
                if (!is_writeable($newDestination)) {
                    return $this->jsonError(
                        $this->translate('A file with the same name exists and is not writeable.'), // @translate
                        Response::STATUS_CODE_500
                    );
                }
                $isFileDifferent = filesize($destination) !== filesize($newDestination)
                    || sha1_file($destination) !== sha1_file($newDestination);
            }
            $result = rename($destination, $newDestination);
            if (!$result) {
                return $this->jsonError(
                    $this->translate('An error occurred when storing file.'), // @translate
                    Response::STATUS_CODE_500
                );
            }
        }

        // Default files used to keep place in temp directory are not removed.
        $this->cleanTempDirectory();

        // Return the data about the file for next step.
        $output = [
            'status' => self::STATUS_SUCCESS,
            'data' => [
                'file' => [
                    'name' => $filename,
                    'path' => $flowRequest->getRelativePath(),
                    // Don't send the full path for security.
                    'tmp_name' => basename($filepath),
                    'size' => $filesize,
                ],
            ],
        ];
        if ($isBulkUploadForm && !empty($fileExists) && !empty($isFileDifferent)) {
            $output['message'] = $this->translate('An existing file was overridden.'); // @translate
        }
        return new JsonModel($output);
    }

    protected function jsonError(string $message, int $statusCode = 400)
    {
        $response = $this->getResponse();
        $response->setStatusCode($statusCode);
        return new JsonModel([
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'code' => $statusCode,
        ]);
    }

    protected function cleanTempDirectory(): void
    {
        if (!$this->tempDir) {
            return;
        }
        $fileSystemIterator = new FilesystemIterator($this->tempDir);
        $threshold = strtotime('-40 day');
        /** @var \SplFileInfo $file */
        foreach ($fileSystemIterator as $file) {
            $filename = $file->getFilename();
            if ($file->isFile()
                && $file->isWritable()
                && $file->getCTime() <= $threshold
                && (
                    // Remove any old placeholder.
                    !$file->getSize()
                    // Remove all old omeka temp files.
                    || (mb_substr($filename, 0, 5) === 'omeka' || mb_substr($filename, 0, 4) === 'omk_')
                )
            ) {
                @unlink($this->tempDir . '/' . $file->getFilename());
            }
        }
    }
}
