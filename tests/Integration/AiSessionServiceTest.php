<?php

declare(strict_types=1);

namespace Tests\Integration;

use AiSessionService;
use PHPUnit\Framework\TestCase;

final class AiSessionServiceTest extends TestCase
{
    private AiSessionService $service;
    private int $userId = 6;
    private ?int $sessionId = null;

    protected function setUp(): void
    {
        $this->service = new AiSessionService();
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== null) {
            try {
                $this->service->deleteSession($this->userId, $this->sessionId);
            } catch (\Throwable) {
                // Cleanup should not hide the original test result.
            }
        }
    }

    public function testCreateAppendReadRenameAndDeleteSession(): void
    {
        $session = $this->service->createSession($this->userId, null, 'Phase 4.1 Test');
        $this->sessionId = (int)$session['id'];

        self::assertSame('Phase 4.1 Test', $session['session_title']);

        $userMessage = $this->service->appendMessage(
            $this->userId,
            $this->sessionId,
            'user',
            'Explain normalization.',
            4
        );
        self::assertSame('user', $userMessage['role']);

        $assistantMessage = $this->service->appendMessage(
            $this->userId,
            $this->sessionId,
            'assistant',
            'Normalization organizes data to reduce redundancy.',
            10
        );
        self::assertSame('assistant', $assistantMessage['role']);

        $messages = $this->service->getMessages($this->userId, $this->sessionId);
        self::assertCount(2, $messages);
        self::assertSame('Explain normalization.', $messages[0]['content']);
        self::assertSame('Normalization organizes data to reduce redundancy.', $messages[1]['content']);

        $renamed = $this->service->renameSession($this->userId, $this->sessionId, 'Normalization Help');
        self::assertSame('Normalization Help', $renamed['session_title']);

        $otherUser = 5;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->service->getSession($otherUser, $this->sessionId);
    }

    public function testQuickPromptsAreLoadedFromDatabase(): void
    {
        $prompts = $this->service->getQuickPrompts();

        self::assertNotEmpty($prompts);
        self::assertArrayHasKey('label', $prompts[0]);
        self::assertArrayHasKey('prompt_text', $prompts[0]);
    }
}
