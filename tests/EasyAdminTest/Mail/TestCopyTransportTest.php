<?php declare(strict_types=1);

namespace EasyAdminTest\Mail;

use EasyAdmin\Mail\TestCopyTransport;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\InMemory;
use PHPUnit\Framework\TestCase;

class TestCopyTransportTest extends TestCase
{
    protected function message(): Message
    {
        $message = new Message();
        $message->setSubject('Password');
        $message->setBody('Body');
        $message->setTo('alice@users.example.org');
        $message->addTo('bob@team.example.org');
        $message->addCc('carol@users.example.org');
        $message->addBcc('dave@team.example.org');
        return $message;
    }

    public function testRedirect(): void
    {
        $inner = new InMemory();
        $transport = new TestCopyTransport($inner, 'redirect', 'tester@team.example.org', [], sys_get_temp_dir());
        $transport->send($this->message());

        $sent = $inner->getLastMessage();
        $this->assertSame(['tester@team.example.org'], array_values(array_map(fn ($a) => $a->getEmail(), iterator_to_array($sent->getTo()))));
        $this->assertCount(0, $sent->getCc());
        $this->assertCount(0, $sent->getBcc());
        $this->assertStringStartsWith('[TEST → alice@users.example.org, bob@team.example.org, carol@users.example.org, dave@team.example.org] Password', $sent->getSubject());
        $this->assertTrue($sent->getHeaders()->has('X-Original-To'));
    }

    public function testTeamOnly(): void
    {
        $inner = new InMemory();
        $transport = new TestCopyTransport($inner, 'team', '', ['team.example.org'], sys_get_temp_dir());
        $transport->send($this->message());

        $sent = $inner->getLastMessage();
        $emails = fn ($list) => array_values(array_map(fn ($a) => $a->getEmail(), iterator_to_array($list)));
        $this->assertSame(['bob@team.example.org'], $emails($sent->getTo()));
        $this->assertSame([], $emails($sent->getCc()));
        $this->assertSame(['dave@team.example.org'], $emails($sent->getBcc()));
    }

    public function testTeamOnlyWithoutTeamRecipientSendsNothing(): void
    {
        $inner = new InMemory();
        $transport = new TestCopyTransport($inner, 'team', '', ['other.example.org'], sys_get_temp_dir());
        $transport->send($this->message());
        $this->assertNull($inner->getLastMessage());
    }

    public function testCaptureAndRedirectWithoutAddress(): void
    {
        foreach (['capture', 'redirect'] as $mode) {
            $dir = sys_get_temp_dir() . '/easyadmin_mailcapture_' . uniqid();
            $inner = new InMemory();
            $transport = new TestCopyTransport($inner, $mode, '', [], $dir);
            $transport->send($this->message());
            try {
                $this->assertNull($inner->getLastMessage(), $mode);
                $files = glob($dir . '/*');
                $this->assertCount(1, $files, $mode);
                $this->assertStringContainsString('alice@users.example.org', file_get_contents($files[0]));
            } finally {
                exec('rm -rf ' . escapeshellarg($dir));
            }
        }
    }
}
