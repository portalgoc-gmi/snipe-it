<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Asset;
use App\Models\User;
use App\Models\Location;
use App\Models\Statuslabel;
use App\Models\Company;
use App\Models\AssetModel;

class ImportPatientAssets extends Command
{
    protected $signature = 'import:patient-assets';
    protected $description = 'Import patient assets from CSV';

    public function handle()
    {
        $path = storage_path('app/patient_assets.csv');

        if (!file_exists($path)) {
            $this->error('CSV file not found!');
            return;
        }

        $rows = array_map('str_getcsv', file($path));
        $header = array_shift($rows);

        // καθάρισμα BOM + spaces στο header
        $header = array_map(function ($h) {
            return trim(str_replace("\xEF\xBB\xBF", '', $h));
        }, $header);

        $company = Company::where('name', 'German Medical Institute')->first();
        $model = AssetModel::where('name', 'Patient Records')->first();
        $archiveLocation = Location::where('name', 'Archive')->first();

        $imported = 0;
        $failed = 0;
        $userNotFound = 0;

        foreach ($rows as $row) {
            if (count($header) !== count($row)) {
                $this->error('BAD ROW columns mismatch: ' . implode(',', $row));
                $failed++;
                continue;
            }

            $data = array_combine($header, $row);

            if (empty($data['his_patient_id'])) {
                $this->error('NO HIS PATIENT ID');
                $failed++;
                continue;
            }

            $tag = trim($data['his_patient_id']);

            // no duplicates: αν υπάρχει, κάνε update αντί για νέο
            $asset = Asset::firstOrNew(['asset_tag' => $tag]);

            $status = Statuslabel::where('name', trim($data['status'] ?? ''))->first();
            $location = Location::where('name', trim($data['location'] ?? ''))->first();

            $user = null;

            if (!empty($data['assigned_user'])) {
                $search = trim(str_replace("\xc2\xa0", ' ', $data['assigned_user']));

                if (preg_match('/\(([^)]+)\)/', $search, $m)) {
                    $short = trim($m[1]);
                } else {
                    $short = trim(explode(' ', $search)[0]);
                }

                $user = User::whereRaw('LOWER(username) = ?', [strtolower($short)])->first();

                if (!$user) {
                    $this->error("USER NOT FOUND: [$search] -> [$short]");
                    $userNotFound++;
                }
            }

            $asset->name = $data['asset_name'] ?? $tag;
            $asset->model_id = $model?->id;
            $asset->company_id = $company?->id;
            $asset->status_id = $status?->id;
            $asset->location_id = $location?->id;
            $asset->rtd_location_id = $archiveLocation?->id;
            $asset->requestable = 1;

            if (!empty($data['warehouse_position'])) {
                $warehouse = trim($data['warehouse_position']);

                if (preg_match('/^(\d+)([A-Z])$/i', $warehouse, $m)) {
                    $warehouse = strtoupper($m[2]) . $m[1];
                }

                $asset->_snipeit_warehouse_position_2 = $warehouse;
            }

            if (!empty($data['patient_status']) && trim($data['patient_status']) === 'YES') {
                $asset->_snipeit_patient_status_5 = 'Deceased';
            }

            if (trim($data['status'] ?? '') === 'With Department' && $user) {
                $asset->assigned_to = $user->id;
                $asset->assigned_type = \App\Models\User::class;
                $asset->location_id = $user->location_id;
                $asset->rtd_location_id = $archiveLocation?->id;
            }

            if (!$asset->isValid() || !$asset->save()) {
                $this->error("FAILED: " . $tag);
                $this->error($asset->getErrors()->first());
                $failed++;
                continue;
            }

            $imported++;
        }

        $this->info("DONE!");
        $this->info("Imported/Updated: " . $imported);
        $this->info("Failed: " . $failed);
        $this->info("Users not found: " . $userNotFound);
    }
}
