<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Violação de regra de negócio de agendamento. A mensagem é segura para exibir ao usuário.
 */
class BookingException extends RuntimeException
{
}
