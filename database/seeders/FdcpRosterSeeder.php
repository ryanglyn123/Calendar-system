<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\Division;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FDCP staff accounts from the HRIS roster (database/seeders/data/fdcp_roster.csv:
 * name, position, division, email, employment_status only, trimmed from the
 * HRIS employee sheet with no IDs, salary or contact details).
 *
 * Everyone starts active and approved as staff with the shared default
 * password. People without a usable email get a placeholder @fdcp.invalid
 * address to replace in Admin → Users. Existing accounts are left untouched.
 */
class FdcpRosterSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'password';

    public function run(): void
    {
        $divisions = Division::query()->pluck('id', 'name');
        $password = Hash::make(self::DEFAULT_PASSWORD); // once, not per user
        $taken = User::withTrashed()->pluck('email')->map(fn ($e) => strtolower($e))->flip();
        $created = 0;
        $placeholders = [];

        foreach ($this->rows() as $row) {
            $email = $row['email'];

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || isset($taken[$email])) {
                // Imported on an earlier run, under this email or a placeholder.
                $imported = User::withTrashed()
                    ->where('name', $row['name'])
                    ->where(fn ($q) => $q->where('email', $email)->orWhere('email', 'like', '%@fdcp.invalid'))
                    ->exists();

                if ($imported) {
                    continue;
                }

                $email = $this->placeholder($row['name'], $taken);
                $placeholders[] = "{$row['name']} → {$email}";
            }

            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $row['name'],
                'password' => $password,
                'division_id' => $divisions[$row['division']] ?? null,
                'status' => UserStatus::Active,
            ]);
            $taken[$email] = true;

            if ($user->wasRecentlyCreated) {
                $user->forceFill(['email_verified_at' => now(), 'approved_at' => now()])->save();
                $user->assignRole('staff');
                $created++;
            }
        }

        $this->command?->info("FDCP roster: {$created} accounts created.");

        foreach ($placeholders as $line) {
            $this->command?->warn("  placeholder email: {$line}");
        }
    }

    /** @return list<array{name: string, division: string, email: string}> */
    private function rows(): array
    {
        $path = database_path('seeders/data/fdcp_roster.csv');
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => trim($h, "\u{FEFF} \""), fgetcsv($handle));
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            $rows[] = [
                'name' => trim($row['name']),
                'division' => trim($row['division']),
                'email' => strtolower(trim($row['email'])),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /** first.last@fdcp.invalid, numbered when two people share a name. */
    private function placeholder(string $name, Collection $taken): string
    {
        $base = Str::slug(Str::ascii($name), '.');
        $email = "{$base}@fdcp.invalid";

        for ($n = 2; isset($taken[$email]); $n++) {
            $email = "{$base}{$n}@fdcp.invalid";
        }

        return $email;
    }
}
