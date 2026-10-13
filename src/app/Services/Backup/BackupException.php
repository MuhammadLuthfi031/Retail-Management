<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Kegagalan backup/restore yang pesannya aman & berguna ditampilkan ke operator
 * (tidak pernah memuat password atau isi data).
 */
class BackupException extends RuntimeException
{
}