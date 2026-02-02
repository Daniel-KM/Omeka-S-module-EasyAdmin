<?php declare(strict_types=1);

namespace EasyAdmin\Delegator;

use Omeka\Api\Adapter\AssetAdapter;
use Omeka\Api\Request;
use Omeka\Entity\EntityInterface;
use Omeka\File\Validator;
use Omeka\Stdlib\ErrorStore;

/**
 * Delegator for AssetAdapter to allow additional media types and extensions.
 *
 * This allows uploading PDFs, SVGs, and other file types as assets,
 * configurable via module settings.
 *
 * Extends AssetAdapter directly to maintain ACL compatibility.
 */
class AssetAdapterDelegator extends AssetAdapter
{
    public function hydrate(Request $request, EntityInterface $entity, ErrorStore $errorStore): void
    {
        $data = $request->getContent();

        if (Request::CREATE === $request->getOperation()) {
            $fileData = $request->getFileData();
            if (!isset($fileData['file'])) {
                $errorStore->addError('file', 'No file was uploaded');
                return;
            }

            $services = $this->getServiceLocator();
            $uploader = $services->get('Omeka\File\Uploader');
            $tempFile = $uploader->upload($fileData['file'], $errorStore);
            if (!$tempFile) {
                return;
            }

            $tempFile->setSourceName($fileData['file']['name']);

            // Get core config and additional settings.
            $config = $services->get('Config');
            $settings = $services->get('Omeka\Settings');

            $allowedMediaTypes = $config['api_assets']['allowed_media_types'];
            $allowedExtensions = $config['api_assets']['allowed_extensions'];

            // Merge additional media types and extensions from settings.
            $additionalMediaTypes = $settings->get('easyadmin_asset_media_types', []);
            $additionalExtensions = $settings->get('easyadmin_asset_extensions', []);

            if ($additionalMediaTypes) {
                $allowedMediaTypes = array_unique(array_merge($allowedMediaTypes, $additionalMediaTypes));
            }
            if ($additionalExtensions) {
                $allowedExtensions = array_unique(array_merge($allowedExtensions, $additionalExtensions));
            }

            $validator = new Validator($allowedMediaTypes, $allowedExtensions);
            if (!$validator->validate($tempFile, $errorStore)) {
                return;
            }

            $this->hydrateOwner($request, $entity);
            $entity->setStorageId($tempFile->getStorageId());
            $entity->setExtension($tempFile->getExtension());
            $entity->setMediaType($tempFile->getMediaType());
            $entity->setName($request->getValue('o:name', $fileData['file']['name']));

            $tempFile->storeAsset();
            $tempFile->delete();
        } else {
            if ($this->shouldHydrate($request, 'o:name')) {
                $entity->setName($request->getValue('o:name'));
            }
        }

        if ($this->shouldHydrate($request, 'o:alt_text')) {
            $entity->setAltText($request->getValue('o:alt_text'));
        }
    }
}
