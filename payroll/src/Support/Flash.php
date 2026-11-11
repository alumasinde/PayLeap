<?php

declare(strict_types=1);

namespace Mshahara\Payroll\Support;

final class Flash
{
    public function __construct(private readonly Session $session)
    {
    }

    public function add(string $type, string $message): void
    {
        $messages = $this->session->get('_flash', []);
        $messages = is_array($messages) ? $messages : [];
        $messages[] = ['type' => $type, 'message' => $message];

        $this->session->put('_flash', $messages);
    }

    /** @return list<array{type:string,message:string}> */
    public function pull(): array
    {
        $messages = $this->session->pull('_flash', []);

        return is_array($messages) ? $messages : [];
    }
}
