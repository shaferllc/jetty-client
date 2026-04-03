<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\TelegramNotifier;
use PHPUnit\Framework\TestCase;

final class TelegramNotifierTest extends TestCase
{
    /** @var list<string> */
    private array $envVars = [
        'JETTY_TELEGRAM_ENABLED',
        'JETTY_TELEGRAM_BOT_TOKEN',
        'JETTY_TELEGRAM_CHAT_ID',
    ];

    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach ($this->envVars as $var) {
            putenv($var);
        }
    }

    public function test_enabled_returns_false_when_explicitly_disabled(): void
    {
        putenv('JETTY_TELEGRAM_ENABLED=0');
        putenv('JETTY_TELEGRAM_BOT_TOKEN=fake-token');
        putenv('JETTY_TELEGRAM_CHAT_ID=12345');

        $this->assertFalse(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_false_when_bot_token_unset(): void
    {
        putenv('JETTY_TELEGRAM_CHAT_ID=12345');

        $this->assertFalse(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_false_when_chat_id_unset(): void
    {
        putenv('JETTY_TELEGRAM_BOT_TOKEN=fake-token');

        $this->assertFalse(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_false_when_both_unset(): void
    {
        $this->assertFalse(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_true_when_token_and_chat_id_set(): void
    {
        putenv('JETTY_TELEGRAM_BOT_TOKEN=fake-token');
        putenv('JETTY_TELEGRAM_CHAT_ID=12345');

        $this->assertTrue(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_true_when_enabled_flag_is_1(): void
    {
        putenv('JETTY_TELEGRAM_ENABLED=1');
        putenv('JETTY_TELEGRAM_BOT_TOKEN=fake-token');
        putenv('JETTY_TELEGRAM_CHAT_ID=12345');

        $this->assertTrue(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_false_when_bot_token_is_empty(): void
    {
        putenv('JETTY_TELEGRAM_BOT_TOKEN=');
        putenv('JETTY_TELEGRAM_CHAT_ID=12345');

        $this->assertFalse(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_false_when_chat_id_is_empty(): void
    {
        putenv('JETTY_TELEGRAM_BOT_TOKEN=fake-token');
        putenv('JETTY_TELEGRAM_CHAT_ID=');

        $this->assertFalse(TelegramNotifier::isEnabled());
    }

    public function test_enabled_returns_false_when_bot_token_is_whitespace(): void
    {
        putenv('JETTY_TELEGRAM_BOT_TOKEN=   ');
        putenv('JETTY_TELEGRAM_CHAT_ID=12345');

        $this->assertFalse(TelegramNotifier::isEnabled());
    }
}
