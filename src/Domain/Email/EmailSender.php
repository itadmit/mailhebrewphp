<?php

declare(strict_types=1);

namespace MailHebrew\Domain\Email;

interface EmailSender
{
    public function send(Email $email): bool;
} 