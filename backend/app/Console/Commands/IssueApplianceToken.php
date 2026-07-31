<?php

namespace App\Console\Commands;

use App\Models\Appliance;
use Illuminate\Console\Command;

class IssueApplianceToken extends Command
{
    protected $signature = 'appliance:token {appliance_id} {--name=acquisition} {--revoke-existing}';

    protected $description = 'Issue an ingest-only API token for an appliance';

    public function handle(): int
    {
        $appliance = Appliance::firstOrCreate(
            ['appliance_id' => $this->argument('appliance_id')],
            ['name' => $this->argument('appliance_id'), 'status' => 'provisioned'],
        );

        if ($this->option('revoke-existing')) {
            $count = $appliance->tokens()->delete();
            $this->warn("revoked {$count} existing token(s)");
        }

        // Ingest only. A leaked appliance token must not be able to read history
        // or change configuration.
        $token = $appliance->createToken($this->option('name'), ['ingest']);

        $this->info("appliance: {$appliance->appliance_id}");
        $this->info('abilities: ingest');
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->comment('Shown once. Store it in /etc/quakevault/forwarder.env (mode 0600).');

        return self::SUCCESS;
    }
}
