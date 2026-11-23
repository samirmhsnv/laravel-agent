<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Messages;

final readonly class ToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}

    /** @return array{id: string, name: string, arguments: array<string, mixed>} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }

    /** @param  array{id: string, name: string, arguments?: array<string, mixed>}  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['name'], $data['arguments'] ?? []);
    }
}
