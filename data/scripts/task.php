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
 * @todo Manage the server url for absolute links (currently via a setting).
 */
namespace Omeka;

use Omeka\Entity\User;
use Omeka\Stdlib\Message;

require dirname(__DIR__, 4) . '/bootstrap.php';

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

$shortopts = 'h:t:u:b::';
$longopts = ['help', 'task:', 'user-id:', 'base-path::'];
$options = getopt($shortopts, $longopts);

foreach ($options as $key => $value) switch ($key) {
    case 't':
    case 'task':
        $taskName = $value;
        break;
    case 'u':
    case 'user-id':
        $userId = $value;
        break;
    case 'b':
    case 'base-path':
        $basePath = $value;
        break;
    case 'h':
    case 'help':
        $message = new Message(
        'Required options: -t --task / -u --user-id
Optional option: -b --base-path' // @translate
        );
        echo $translator->translate($message) . PHP_EOL;
        exit();
}

if (empty($taskName)) {
    $message = new Message(
        'The task name must be set and exist.' // @translate
    );
    exit($translator->translate($message) . PHP_EOL);
}

// TODO Use the plugin manager.
$omekaModulesPath = OMEKA_PATH . '/modules';
$modulePaths = array_values(array_filter(array_diff(scandir($omekaModulesPath), ['.', '..']), function ($file) use ($omekaModulesPath) {
    return is_dir($omekaModulesPath . '/' . $file);
}));
foreach ($modulePaths as $modulePath) {
    $filepath = $omekaModulesPath . '/' . $modulePath . '/src/Job/' . $taskName . '.php';
    if (file_exists($filepath) && filesize($filepath) && is_readable($filepath)) {
        include_once $filepath;
        $taskClass = $modulePath . '\\Job\\' . $taskName;
        if (class_exists($taskClass)) {
            $job = new \Omeka\Entity\Job;
            $task = new $taskClass($job, $services);
            break;
        }
    }
}

if (empty($task)) {
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

$entityManager = $services->get('Omeka\EntityManager');
$user = $entityManager->find(User::class, $userId);
if (empty($user)) {
    $message = new Message(
        'The user #%d is set for the task "%s", but doesn’t exist.', // @translate
        $userId,
        $taskName
    );
    $logger->err($message);
    exit($translator->translate($message) . PHP_EOL);
}

if (!empty($basePath)) {
    $services->get('ViewHelperManager')->get('BasePath')->setBasePath($basePath);
}

$services->get('Omeka\AuthenticationService')->getStorage()->write($user);

// Finalize the preparation of the job / task.
$job->setOwner($user);
$job->setClass($taskClass);

// Since it’s a job not prepared as a job, the logger should be prepared here.
/** @var \Omeka\Module\Module $module */
$module = $services->get('Omeka\ModuleManager')->getModule('Log');
if ($module && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE) {
    $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
    $referenceIdProcessor->setReferenceId('task/' . $taskName . '/' . (new \DateTime())->format('Ymd-His'));
    $logger->addProcessor($referenceIdProcessor);
    $userIdProcessor = new \Log\Processor\UserId($user);
    $logger->addProcessor($userIdProcessor);
}

// TODO Log fatal errors.
// @see \Omeka\Job\DispatchStrategy::handleFatalError();
// @link https://stackoverflow.com/questions/1900208/php-custom-error-handler-handling-parse-fatal-errors#7313887

try {
    $logger->info('Task is starting.'); // @translate
    $task->perform();
    $logger->info('Task ended.'); // @translate
} catch (\Exception $e) {
    $logger->err($e);
}
