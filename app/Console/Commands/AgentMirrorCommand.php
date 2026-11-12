<?php

namespace App\Console\Commands;

use App\Models\AgentMirrorSync;
use App\Models\AgentPendingAction;
use App\Models\Gym;
use App\Services\AgentApi\MirrorService;
use Illuminate\Console\Command;

/**
 * Runs a gym as a mirror of another gymportal installation.
 *
 *   php artisan agent:mirror import storage/exports/gym_export_x.json --create
 *   php artisan agent:mirror import <file> --gym=my-gym
 *   php artisan agent:mirror status --gym=my-gym
 *   php artisan agent:mirror actions --gym=my-gym
 */
class AgentMirrorCommand extends Command
{
    protected $signature = 'agent:mirror
        {action : import, status or actions}
        {file? : Path of the studio export (for import)}
        {--gym= : Gym id or slug; defaults to the slug inside the export}
        {--create : Create the gym when it does not exist yet}';

    protected $description = 'Mirror a gym from a gymportal studio export';

    public function handle(MirrorService $mirror): int
    {
        return match ($this->argument('action')) {
            'import' => $this->import($mirror),
            'status' => $this->status(),
            'actions' => $this->actions(),
            default => $this->fail('Action must be import, status or actions.'),
        };
    }

    private function import(MirrorService $mirror): int
    {
        $file = (string) $this->argument('file');

        if ($file === '' || ! is_file($file)) {
            $this->error('Export file not found.');

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            $this->error('The file is not valid JSON.');

            return self::FAILURE;
        }

        $key = $this->option('gym') ?? ($data['export_metadata']['gym_slug'] ?? null);
        $gym = $this->findGym($key);

        try {
            $result = $mirror->import($data, $gym, 'file:'.basename($file), (bool) $this->option('create'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sync = $result['sync'];

        $this->info(($result['created'] ? 'Gym created and mirrored: ' : 'Mirror updated: ').$result['gym']->name.' ('.$result['gym']->slug.')');
        $this->line('Export taken: '.(optional($sync->exported_at)->toDateTimeString() ?? 'unknown'));
        $this->line('Imported: '.collect($sync->stats)->filter()->map(fn ($n, $k) => $n.' '.$k)->implode(', '));
        $this->line('Pending actions closed by this import: '.$sync->actions_closed);

        return self::SUCCESS;
    }

    private function status(): int
    {
        $gym = $this->findGym($this->option('gym'));
        $sync = $gym ? AgentMirrorSync::latestFor($gym->id) : null;

        if (! $sync) {
            $this->error('No mirror found. Pass --gym=<id or slug>.');

            return self::FAILURE;
        }

        $this->line('Gym: '.$gym->name.' ('.$gym->slug.')');
        $this->line('Export taken: '.(optional($sync->exported_at)->toDateTimeString() ?? 'unknown'));
        $this->line('Imported: '.$sync->created_at->toDateTimeString());
        $this->line('Open actions: '.AgentPendingAction::where('gym_id', $gym->id)->where('status', 'open')->count());

        return self::SUCCESS;
    }

    private function actions(): int
    {
        $gym = $this->findGym($this->option('gym'));

        if (! $gym) {
            $this->error('Gym not found. Pass --gym=<id or slug>.');

            return self::FAILURE;
        }

        $rows = AgentPendingAction::where('gym_id', $gym->id)->where('status', 'open')->orderBy('id')->get()
            ->map(fn (AgentPendingAction $a) => [$a->id, $a->type, $a->member_number, $a->member_name, json_encode($a->payload, JSON_UNESCAPED_UNICODE), $a->created_at->toDateTimeString()]);

        $this->table(['ID', 'Type', 'Member no.', 'Name', 'Payload', 'Created'], $rows);

        return self::SUCCESS;
    }

    private function findGym(mixed $key): ?Gym
    {
        if ($key === null || $key === '') {
            return null;
        }

        return ctype_digit((string) $key) ? Gym::find((int) $key) : Gym::where('slug', $key)->first();
    }
}
