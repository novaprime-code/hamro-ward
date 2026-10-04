<?php

declare(strict_types=1);

namespace App\Modules\Staff\Console;

use App\Modules\Audit\Actions\RecordAuditEvent;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Staff\Enums\GlobalRole;
use App\Modules\Staff\Models\StaffUser;
use Illuminate\Console\Command;

/**
 * `hw:staff:create {email}` — a staff account, from the server's console.
 *
 * How the first operator admin comes to exist: staff are invite-only, and
 * the invitation screen needs an operator admin to send it. The password is
 * asked for, never taken as an argument, so it does not land in shell
 * history or a process list. The account has no two-factor until its first
 * sign-in, which forces enrolment; until then it can reach nothing.
 */
final class CreateStaffCommand extends Command
{
    protected $signature = 'hw:staff:create
                            {email : Sign-in address}
                            {--name= : Display name (asked for when omitted)}
                            {--operator-admin : Grant the global operator_admin role}';

    protected $description = 'Create a staff account (two-factor enrolment is forced at first sign-in)';

    public function handle(RecordAuditEvent $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("Not an email address: {$email}");

            return self::INVALID;
        }

        if (StaffUser::query()->where('email', $email)->exists()) {
            $this->components->error("A staff account for {$email} already exists.");

            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
        $password = (string) $this->secret('Password (at least 12 characters)');

        if (mb_strlen($password) < 12) {
            $this->components->error('The password must be at least 12 characters.');

            return self::INVALID;
        }

        if ($password !== (string) $this->secret('Password again')) {
            $this->components->error('The two passwords differ.');

            return self::INVALID;
        }

        $staff = StaffUser::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);

        if ($this->option('operator-admin')) {
            $staff->forceFill(['global_role' => GlobalRole::OperatorAdmin])->save();
        }

        $audit->central(ActorType::System, 'staff_user.created', 'staff_user', $staff->id, [
            'via' => 'hw:staff:create',
            'operator_admin' => (bool) $this->option('operator-admin'),
        ]);

        $this->components->info("Created {$email}. Two-factor enrolment happens at first sign-in on the admin host.");

        return self::SUCCESS;
    }
}
