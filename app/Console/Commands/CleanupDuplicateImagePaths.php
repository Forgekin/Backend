<?php

namespace App\Console\Commands;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupDuplicateImagePaths extends Command
{
    /**
     * Legacy uploads named files after the account name only (no id), so two
     * same-/empty-named accounts could end up pointing at the SAME stored file.
     * Replacing one then visibly changed the other. This command finds image
     * paths referenced by more than one account and clears them so the affected
     * accounts fall back to initials until they re-upload (which now produces a
     * unique filename and can no longer collide).
     */
    protected $signature = 'images:cleanup-duplicates
                            {--dry-run : Preview the duplicates without modifying anything}
                            {--delete-files : Also delete the now-orphaned files from storage}';

    protected $description = 'Clear image paths shared by more than one account (legacy non-unique filenames).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $deleteFiles = (bool) $this->option('delete-files');

        $targets = [
            ['model' => User::class,       'column' => 'profile_image', 'label' => 'System users'],
            ['model' => Freelancer::class, 'column' => 'profile_image', 'label' => 'Freelancers'],
            ['model' => Employer::class,   'column' => 'company_logo',  'label' => 'Employers'],
        ];

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made.');
        }

        $totalCleared = 0;
        $orphanPaths = [];

        foreach ($targets as $target) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
            $model = $target['model'];
            $column = $target['column'];
            $label = $target['label'];

            // Path values held by more than one record.
            $duplicatePaths = $model::query()
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->pluck($column);

            if ($duplicatePaths->isEmpty()) {
                $this->line("✓ {$label}: no shared image paths.");
                continue;
            }

            foreach ($duplicatePaths as $path) {
                $ids = $model::where($column, $path)->pluck('id');
                $this->warn("• {$label}: \"{$path}\" is shared by {$ids->count()} accounts (IDs: {$ids->implode(', ')})");

                if (! $dryRun) {
                    // Clear ALL sharers: the true owner can't be determined, so the
                    // only safe outcome is for each to re-upload its own image.
                    $model::where($column, $path)->update([$column => null]);
                }

                $totalCleared += $ids->count();
                $orphanPaths[] = $path;
            }
        }

        if ($dryRun) {
            $this->info("Would clear {$totalCleared} account image reference(s). Run without --dry-run to apply.");
            return self::SUCCESS;
        }

        $this->info("Cleared {$totalCleared} duplicated image reference(s). Affected accounts now show initials until they re-upload.");

        if ($deleteFiles && ! empty($orphanPaths)) {
            $deleted = 0;
            foreach (array_unique($orphanPaths) as $path) {
                $relative = ltrim(preg_replace('#^/?(public/)?storage/#', '', $path), '/');
                if (Storage::disk('public')->exists($relative)) {
                    Storage::disk('public')->delete($relative);
                    $deleted++;
                }
            }
            $this->info("Deleted {$deleted} orphaned file(s) from the public disk.");
        }

        return self::SUCCESS;
    }
}
