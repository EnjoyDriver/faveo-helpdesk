<?php

namespace App\Notifications\Messages;

class WhatsAppMessage
{
    public string $content;

    public ?string $deviceId = null;

    public function __construct(string $content = '')
    {
        $this->content = $content;
    }

    public static function create(string $content = ''): self
    {
        return new self($content);
    }

    public function content(string $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function deviceId(string $deviceId): self
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    public function line(string $line): self
    {
        $this->content .= ($this->content ? "\n" : '').$line;

        return $this;
    }

    public function intro(string $text): self
    {
        return $this->line($text);
    }

    public function outro(string $text): self
    {
        return $this->line($text);
    }

    public function action(string $text, string $url): self
    {
        return $this->line("{$text}: {$url}");
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'device_id' => $this->deviceId,
        ];
    }
}
