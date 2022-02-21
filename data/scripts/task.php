<?php declare(strict_types=1);

/**
 * Prepare the application to process a task, usually via cron.
 *
 * A task is a standard Omeka job that is not managed inside Omeka.
 *
 * By construction, no control of the user is done. It is managed from the task.
 * Nevertheless, the process is checked and must be a system one, not a web one.
 * The class must be a job one.
 *
 * The modules are not available inside this file (no Log\Stdlib\PsrMessage).
 *
 * @todo Use the true Laminas console routing system.
 */

require dirname(__DIR__, 4) . '/bootstrap.php';

use Omeka\Stdlib\Message;

$help = <<<'MSG'
Usage: php data/scripts/task.php [arguments]

Required arguments:
  -t --task [Name]
		Full class of a job ("EasyAdmin\Job\LoopItems"). May be its
		basename ("LoopItems"). You should take care of case
		sensitivity and escaping "\" or quoting name on cli.

  -u --user-id [#id]
		The Omeka user id is required, else the job won’t have any
		rights.

Recommended arguments:
  -s --server-url [url]
		The url of the server to build resource urls (default:
		"http://localhost").

  -b --base-path [path]
		The url path to complete the server url (default: "/").

Optional arguments:
  -a --args [json]
		Arguments to pass to the task. Arguments are specific to
		each job. To find them, check the code, or run a job
		manually then check the job page in admin interface.

  -j --job
		Create a standard job that will be checkable in admin
		interface. In any case, all logs are available in logs with
		a reference code. It allows to process some rare jobs that
		are not taskable too.

  -h --help
		This help.
MSG;
// @translate

$taskName = null;
$userId = null;
$serverUrl = 'http://localhost';
$basePath = '/';
$jobArgs = [];
$asJob = false;

$application = \Omeka\Mvc\Application::init(require OMEKA_PATH . '/application/config/application.config.php');
$services = $application->getServiceManager();
/** @var \Laminas\Log\Logger $logger */
$logger = $services->get('Omeka\Logger');
$translator = $services->get('MvcTranslator');

if (php_sapi_name() !== 'cli') {
    $message = new Message(
        'The script "%s" must be run from the command line.', // @translate
        __FILE__
    );
    $logger->err($message);
    exit($translator->translate($message) . PHP_EOL);
}

$shortopts = 'ht:u:b:s:a:j';
$longopts = ['help', 'task:', 'user-id:', 'base-path:', 'server-url:', 'args:', 'job'];
$options = getopt($shortopts, $longopts);

if (!$options) {
    echo $translator->translate($help) . PHP_EOL;
    exit();
}

foreach ($options as $key => $value) switch ($key) {
    case 't':
    case 'task':
        $taskName = $value;
        break;
    case 'u':
    case 'user-id':
        $userId = $value;
        break;
    case 's':
    case 'server-url':
        $serverUrl = $value;
        break;
    case 'b':
    case 'base-path':
        $basePath = $value;
        break;
    case 'a':
    case 'args':
        $jobArgs = json_decode($value, true);
        if (!is_array($jobArgs)) {
            $message = new Message(
                'The job arguments are not a valid json object.' // @translate
            );
            echo $translator->translate($message) . PHP_EOL;
            exit();
        }
        break;
    case 'j':
    case 'job':
        $asJob = true;
        break;
    case 'h':
    case 'help':
        $message = new Message($help);
        echo $translator->translate($message) . PHP_EOL;
        exit();
}

if (empty($taskName)) {
    $message = new Message(
        'The task name must be set and exist.' // @translate
    );
    echo $translator->translate($message) . PHP_EOL . PHP_EOL;
    echo $translator->translate($help) . PHP_EOL;
    exit();
}

// TODO Use the plugin manager.
$omekaModulesPath = OMEKA_PATH . '/modules';
$modulePaths = array_values(array_filter(array_diff(scandir($omekaModulesPath), ['.', '..']), function ($file) use ($omekaModulesPath) {
    return is_dir($omekaModulesPath . '/' . $file);
}));
// Short task name.
if (strpos($taskName, '\\') === false) {
    foreach ($modulePaths as $modulePath) {
        $filepath = $omekaModulesPath . '/' . $modulePath . '/src/Job/' . $taskName . '.php';
        if (file_exists($filepath) && filesize($filepath) && is_readable($filepath)) {
            include_once $filepath;
            $taskClass = $modulePath . '\\Job\\' . $taskName;
            if (!class_exists($taskClass)) {
                $taskClass = null;
            }
            break;
        }
    }
}
// Full class name.
else {
    $modulePath = strtok($taskName, '\\');
    $baseTaskName = substr(strrchr($taskName, '\\'), 1);
    $filepath = $omekaModulesPath . '/' . $modulePath . '/src/Job/' . $baseTaskName . '.php';
    if (file_exists($filepath) && filesize($filepath) && is_readable($filepath)) {
        include_once $filepath;
        $taskClass = $taskName;
        if (!class_exists($taskClass)) {
            $taskClass = null;
        }
    }
}

if (empty($taskClass)) {
    $message = new Message(
        'The task "%s" should be set and exist.', // @translate
        $taskName
    );
    exit($translator->translate($message) . PHP_EOL);
}

if (empty($userId)) {
    $message = new Message(
        'The user id must be set and exist.' // @translate
    );
    exit($translator->translate($message) . PHP_EOL);
}

/** @var \Doctrine\ORM\EntityManager $entityManager */
$entityManager = $services->get('Omeka\EntityManager');
try {
    $user = $entityManager->find(\Omeka\Entity\User::class, $userId);
} catch (\Exception $e) {
    $message = new Message(
        'The database does not exist.' // @translate
    );
    exit($translator->translate($message) . PHP_EOL);
}
if (empty($user)) {
    $message = new Message(
        'The user #%d is set for the task "%s", but doesn’t exist.', // @translate
        $userId,
        $taskName
    );
    $logger->err($message);
    exit($translator->translate($message) . PHP_EOL);
}

if (empty($serverUrl)) {
    $serverUrl = 'http://localhost';
}
$serverUrlParts = parse_url($serverUrl);
$scheme = $serverUrlParts['scheme'];
$host = $serverUrlParts['host'];
if (isset($serverUrlParts['port'])) {
    $port = $serverUrlParts['port'];
} elseif ($serverUrlParts['scheme'] === 'http') {
    $port = 80;
} elseif ($serverUrlParts['scheme'] === 'https') {
    $port = 443;
} else {
    $port = null;
}
/** @var \Laminas\View\Helper\ServerUrl $serverUrlHelper */
$serverUrlHelper = $services->get('ViewHelperManager')->get('ServerUrl');
$serverUrlHelper
    ->setScheme($scheme)
    ->setHost($host)
    ->setPort($port);

$basePath = '/' . trim((string) $basePath, '/');
$services->get('ViewHelperManager')->get('BasePath')
    ->setBasePath($basePath);
$services->get('Router')->setBaseUrl($basePath);

$services->get('Omeka\AuthenticationService')->getStorage()->write($user);

// Since it’s a job not prepared as a job, the logger should be prepared here.
/** @var \Omeka\Module\Module $module */
$module = $services->get('Omeka\ModuleManager')->getModule('Log');
$referenceId = null;
if ($module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE) {
    $referenceId = 'task:' . str_replace('\\', '/', $taskName) . ':' . (new \DateTime())->format('Ymd-His');
    $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
    $referenceIdProcessor->setReferenceId($referenceId);
    $logger->addProcessor($referenceIdProcessor);
    $userIdProcessor = new \Log\Processor\UserId($user);
    $logger->addProcessor($userIdProcessor);
}

// TODO Log fatal errors.
// @see \Omeka\Job\DispatchStrategy::handleFatalError();
// @link https://stackoverflow.com/questions/1900208/php-custom-error-handler-handling-parse-fatal-errors#7313887

// Finalize the preparation of the job / task.
$job = new \Omeka\Entity\Job;
$job->setOwner($user);
$job->setClass($taskClass);
$job->setArgs($jobArgs);
$job->setPid(getmypid());

if ($asJob) {
    $entityManager->persist($job);
    $entityManager->flush();
    // Task is not needed: run the job directly below.
    // $task = new $taskClass($job, $services);
} else {
    // Since there is no job id, the job should not require it.
    // For example, the `shouldStop()` should not be called.
    // Using a dynamic super-class bypasses this issue in most of the real life
    // cases.
    // @todo Fix \Omeka\Job\AbstractJob::shouldStop().
    class_alias($taskClass, 'RealTask');
    class Task extends \RealTask
    {
        public function shouldStop()
        {
            return $this->job->getId()
                ? parent::shouldStop()
                : false;
        }
    }
    $task = new Task($job, $services);
}

$jobId = $job->getId();
if ($referenceId && $jobId) {
    echo $translator->translate(new Message('Task "%s" is starting (job: #%d, reference: %s).', $taskName, $jobId, $referenceId)) . PHP_EOL; // @translate
} elseif ($referenceId) {
    echo $translator->translate(new Message('Task "%s" is starting (reference: %s).', $taskName, $referenceId)) . PHP_EOL; // @translate
} elseif ($job->getId()) {
    echo $translator->translate(new Message('Task "%s" is starting (job: #%d).', $taskName, $jobId)) . PHP_EOL; // @translate
} else {
    echo $translator->translate(new Message('Task "%s" is starting.', $taskName)) . PHP_EOL; // @translate
}

$logger->info('Task is starting.'); // @translate

try {
    // Run as standard job when a job is set.
    if ($asJob) {
        // See Omeka script "perform-job.php".
        $strategy = $services->get('Omeka\Job\DispatchStrategy\Synchronous');
        $services->get('Omeka\Job\Dispatcher')->send($job, $strategy);
        $job->setPid(null);
        $entityManager->flush();
    } else {
        $task->perform();
    }
} catch (\Exception $e) {
    echo $translator->translate(new Message('Task "%s" has an error: %s', $taskName, $e->getMessage())) . PHP_EOL; // @translate
    $logger->err($e);
    exit();
}

$logger->info('Task ended.'); // @translate
echo $translator->translate(new Message('Task "%s" ended.', $taskName)) . PHP_EOL; // @translate
