<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Support\AdminScope;
use App\Support\TenantProvisioner;
use App\Support\TenantTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Product admin: all companies, and can give a company its OWN database.
 * Company admin: only the companies he manages (profile, logo, image, payment details - not the code, status or database).
 */
class CompaniesController extends Controller
{
    private function rules(?int $ignore = null): array
    {
        return [
            'code'                 => ['required', 'alpha_num', 'max:10', Rule::unique('companies', 'code')->ignore($ignore)],
            'name'                 => ['required', 'string', 'max:255'],
            'gstin'                => ['nullable', 'string', 'max:20'],
            'phone'                => ['nullable', 'string', 'max:20'],
            'email'                => ['nullable', 'email', 'max:255'],
            'address'              => ['nullable', 'string', 'max:500'],
            'city'                 => ['nullable', 'string', 'max:255'],
            'state'                => ['nullable', 'string', 'max:255'],
            'pincode'              => ['nullable', 'string', 'max:10'],
            'status'               => ['required', Rule::in(['active', 'suspended', 'inactive'])],
            'allow_negative_stock' => ['boolean'],
            // how customers pay
            'upi_id'               => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\-]{2,}@[A-Za-z0-9.\-]{2,}$/'],
            'upi_name'             => ['nullable', 'string', 'max:120'],
            'bank_name'            => ['nullable', 'string', 'max:255'],
            'account_holder'       => ['nullable', 'string', 'max:255'],
            'account_number'       => ['nullable', 'string', 'max:40'],
            'ifsc'                 => ['nullable', 'string', 'max:20'],
            'bank_branch'          => ['nullable', 'string', 'max:255'],
        ];
    }

    /** find the company and make sure this admin may touch it */
    private function scoped(Request $request, int $id, string $message = 'You can only change your own company.'): Company
    {
        $limit = AdminScope::limit($request->user());
        $company = Company::findOrFail($id);
        abort_if($limit !== null && !in_array($company->id, $limit, true), 403, $message);

        return $company;
    }

    public function index(Request $request): JsonResponse
    {
        $limit = AdminScope::limit($request->user());

        $rows = Company::withCount('users')
            ->when($limit !== null, fn ($q) => $q->whereIn('id', $limit))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('code', 'LIKE', "%{$request->search}%")
                ->orWhere('name', 'LIKE', "%{$request->search}%")->orWhere('gstin', 'LIKE', "%{$request->search}%")))
            ->orderBy('code')->get();

        return $this->ok($rows);
    }

    /**
     * product admin only (route middleware)
     * Optional: create_database + database_name + tables[]  -> the company gets its own database with only those tables.
     */
    public function store(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $request->merge(['code' => strtoupper((string) $request->code)]);
        $data = $request->validate($this->rules() + [
            'create_database' => ['boolean'],
            'database_name'   => ['required_if:create_database,true', 'nullable', 'string', 'max:48'],
            'tables'          => ['required_if:create_database,true', 'nullable', 'array'],
            'tables.*'        => ['string', 'max:64'],
        ]);

        $wantsDb = (bool) ($data['create_database'] ?? false);
        if ($wantsDb) {
            $provisioner->validateName($data['database_name']);   // fail before anything is created
        }
        $company = DB::transaction(fn () => Company::create(collect($data)->except(['create_database', 'database_name', 'tables'])->all()));

        if ($wantsDb) {
            try {
                $company = $provisioner->provision($company, $data['database_name'], $data['tables'] ?? [], false);
            } catch (\Throwable $e) {
                $company->delete();   // no half-made company without its database
                throw $e;
            }
        }

        return $this->ok($company, $wantsDb ? 'Company and its database created' : 'Company created', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $limit = AdminScope::limit($request->user());
        $company = Company::findOrFail($id);

        if ($limit !== null) {
            abort_unless(in_array($company->id, $limit, true), 403, 'You can only change your own company.');
            // a company admin edits the profile only - not the code and not the status
            $request->merge(['code' => $company->code, 'status' => $company->status]);
        } else {
            $request->merge(['code' => strtoupper((string) $request->code)]);
        }

        $company->update($request->validate($this->rules($company->id)));

        return $this->ok($company->fresh(), 'Company updated');
    }

    /** POST companies/{id}/logo | image   (multipart, field name: file) */
    public function upload(Request $request, int $id, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['logo', 'image'], true), 404);
        $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        $company = $this->scoped($request, $id);
        if ($company->{$kind}) {
            Storage::disk('public')->delete($company->{$kind});
        }
        $company->update([$kind => $request->file('file')->store("company-{$kind}s", 'public')]);

        return $this->ok($company->fresh(), ucfirst($kind) . ' updated');
    }

    /** DELETE companies/{id}/logo | image  -> screens fall back to the default MK logo */
    public function removeFile(Request $request, int $id, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, ['logo', 'image'], true), 404);
        $company = $this->scoped($request, $id);

        if ($company->{$kind}) {
            Storage::disk('public')->delete($company->{$kind});
            $company->update([$kind => null]);
        }

        return $this->ok($company->fresh(), ucfirst($kind) . ' removed');
    }

    /** product admin only (route middleware) */
    public function destroy(int $id, TenantProvisioner $provisioner): JsonResponse
    {
        $company = Company::findOrFail($id);

        // does the company have any data? (in its own database, or in the shared one)
        if ($company->database_name) {
            $counts = collect($provisioner->counts($company))->filter(fn ($n) => $n > 0);
        } else {
            $counts = collect(TenantTables::selectable())
                ->mapWithKeys(fn ($t) => [$t => (int) DB::table($t)->where('company_id', $company->id)->count()])->filter(fn ($n) => $n > 0);
        }
        if ($counts->isNotEmpty()) {
            return $this->fail('This company already has data (' . str_replace('_', ' ', $counts->keys()->first()) . '). Mark it inactive instead of deleting.', 422);
        }

        foreach (['logo', 'image'] as $kind) {
            if ($company->{$kind}) {
                Storage::disk('public')->delete($company->{$kind});
            }
        }
        $provisioner->drop($company);   // its (empty) own database
        $company->delete();

        return $this->ok(null, 'Company deleted');
    }

    /** Users who can open this company, with their role there */
    public function users(Request $request, int $id): JsonResponse
    {
        $company = $this->scoped($request, $id, 'You can only see your own company.');

        $roles = Role::pluck('name', 'id');

        return $this->ok($company->users()->orderBy('name')->get()->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_active' => $u->is_active,
            'role' => $roles[$u->pivot->role_id] ?? null, 'is_default' => (bool) $u->pivot->is_default,
        ]));
    }
}
