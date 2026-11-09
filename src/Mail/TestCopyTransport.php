<?php declare(strict_types=1);

namespace EasyAdmin\Mail;

use Laminas\Log\LoggerInterface;
use Laminas\Mail\Address\AddressInterface;
use Laminas\Mail\AddressList;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\File;
use Laminas\Mail\Transport\FileOptions;
use Laminas\Mail\Transport\TransportInterface;

/**
 * Protect the real users of a test copy of a site from its emails.
 *
 * A copy of the database contains the real accounts and the real recipients
 * of the notifications, so the emails are redirected to one address, limited
 * to the domains of the team, or captured in files instead of being sent.
 */
class TestCopyTransport implements TransportInterface
{
    const MODES = ['redirect', 'team', 'capture'];

    /**
     * @var TransportInterface
     */
    protected $transport;

    /**
     * @var string
     */
    protected $mode;

    /**
     * @var string
     */
    protected $redirectTo;

    /**
     * @var string[]
     */
    protected $teamDomains;

    /**
     * @var string
     */
    protected $capturePath;

    /**
     * @var LoggerInterface|null
     */
    protected $logger;

    public function __construct(
        TransportInterface $transport,
        string $mode,
        string $redirectTo,
        array $teamDomains,
        string $capturePath,
        ?LoggerInterface $logger = null
    ) {
        $this->transport = $transport;
        $this->mode = $mode;
        $this->redirectTo = $redirectTo;
        $this->teamDomains = array_map('strtolower', $teamDomains);
        $this->capturePath = $capturePath;
        $this->logger = $logger;
    }

    public function send(Message $message)
    {
        $recipients = [];
        foreach (['getTo', 'getCc', 'getBcc'] as $method) {
            foreach ($message->$method() as $address) {
                $recipients[] = $address->getEmail();
            }
        }

        if ($this->mode === 'redirect' && $this->redirectTo !== '') {
            $message->setTo($this->redirectTo);
            $message->setCc(new AddressList());
            $message->setBcc(new AddressList());
            $message->getHeaders()->addHeaderLine('X-Original-To', implode(', ', $recipients));
            $message->setSubject(sprintf('[TEST → %s] %s', implode(', ', $recipients), $message->getSubject()));
            $this->transport->send($message);
            return;
        }

        if ($this->mode === 'team') {
            $removed = [];
            foreach (['To', 'Cc', 'Bcc'] as $type) {
                $kept = new AddressList();
                foreach ($message->{'get' . $type}() as $address) {
                    if ($this->isTeam($address)) {
                        $kept->add($address);
                    } else {
                        $removed[] = $address->getEmail();
                    }
                }
                $message->{'set' . $type}($kept);
            }
            if ($removed) {
                $this->log('Test copy: email "{subject}" not sent to {emails}, outside the domains of the team.', $message, $removed); // @translate
            }
            if (count($message->getTo()) + count($message->getCc()) + count($message->getBcc())) {
                $this->transport->send($message);
            }
            return;
        }

        // Capture, or redirection without address.
        if (!is_dir($this->capturePath)) {
            @mkdir($this->capturePath, 0775, true);
        }
        $file = new File(new FileOptions(['path' => $this->capturePath]));
        $file->send($message);
        $this->log('Test copy: email "{subject}" to {emails} captured in "{path}", not sent.', $message, $recipients, basename($file->getLastFile())); // @translate
    }

    protected function isTeam(AddressInterface $address): bool
    {
        $domain = strtolower((string) substr(strrchr($address->getEmail(), '@'), 1));
        return in_array($domain, $this->teamDomains, true);
    }

    protected function log(string $template, Message $message, array $emails, string $path = ''): void
    {
        if ($this->logger) {
            $this->logger->notice($template, [
                'subject' => $message->getSubject(),
                'emails' => implode(', ', $emails),
                'path' => 'files/mailcapture/' . $path,
            ]);
        }
    }
}
