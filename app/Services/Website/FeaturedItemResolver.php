<?php

namespace App\Services\Website;

use App\Enums\FeaturedItemType;
use App\Models\FeaturedItem;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns the loose pointers in `featured_items` into labels and into the pick lists Admin chooses from (spec 7.9).
 * Only records the public could see right now are offered, and a pointer to a missing or no longer public record
 * resolves to null, so the admin screens can show it as broken and the public pages skip it.
 */
class FeaturedItemResolver
{
    /**
     * One query per type, whatever the number of rows.
     *
     * @param  Collection<int, FeaturedItem>  $items
     * @return array<string, string> "type:id" => label, for the items that still resolve
     */
    public function labels(Collection $items): array
    {
        $labels = [];

        foreach ($items->groupBy(fn (FeaturedItem $item): string => $item->type->value) as $type => $group) {
            $type = FeaturedItemType::from($type);

            foreach ($this->visible($type)->whereIn($this->idColumn($type), $group->pluck('item_id')->all())->get() as $record) {
                $labels[$type->value.':'.$this->idOf($type, $record)] = $this->labelOf($type, $record);
            }
        }

        return $labels;
    }

    /** @return array<int, string> id => label, for the picker. */
    public function options(FeaturedItemType $type, ?string $search = null, int $limit = 25): array
    {
        $query = $this->visible($type);

        if (filled($search)) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where($this->searchColumn($type), 'like', $like);
        }

        return $query->limit($limit)->get()
            ->mapWithKeys(fn ($record): array => [$this->idOf($type, $record) => $this->labelOf($type, $record)])
            ->all();
    }

    /**
     * The admin's active picks of one type, in the admin's order, limited to records the public can see right now.
     * A pick that is missing or no longer public is skipped.
     *
     * @param  list<string>  $with  relations to load, so the page that shows them does not query per row
     * @param  list<string>  $columns  columns to read, so a cached copy holds only what the page shows
     * @return Collection<int, Model>
     */
    public function featured(FeaturedItemType $type, int $limit, array $with = [], array $columns = ['*']): Collection
    {
        $ids = FeaturedItem::query()->active()->ofType($type)->ordered()->pluck('item_id')->all();

        if ($ids === []) {
            return new Collection;
        }

        $records = $this->visible($type)->select($columns)->with($with)->whereIn($this->idColumn($type), $ids)->get()
            ->keyBy(fn ($record): int => $this->idOf($type, $record));

        return collect($ids)->map(fn (int $id) => $records->get($id))->filter()->take($limit)->values();
    }

    public function label(FeaturedItemType $type, int $id): ?string
    {
        $record = $this->visible($type)->where($this->idColumn($type), $id)->first();

        return $record === null ? null : $this->labelOf($type, $record);
    }

    /**
     * The records of this type the public may see.
     *
     * @return Builder<*>
     */
    private function visible(FeaturedItemType $type): Builder
    {
        return match ($type) {
            FeaturedItemType::OpenRole => OpenRole::query()->visibleTo(null),
            FeaturedItemType::Contributor => Profile::query()->publiclyVisible()->with('user'),
            FeaturedItemType::Organization => Organization::query()->public(),
            FeaturedItemType::ProofOfWork => ProofOfWork::query()->publiclyVisible(),
            FeaturedItemType::Testimonial => Testimonial::query()->active(),
            FeaturedItemType::Project => Project::query()->whereIn('status', ['accepted', 'closed'])->whereIn('showcase_consent', ['anonymous', 'named']),
        };
    }

    /** Contributors are featured by user id, so the pointer survives a profile being rebuilt. */
    private function idColumn(FeaturedItemType $type): string
    {
        return $type === FeaturedItemType::Contributor ? 'profiles.user_id' : $this->table($type).'.id';
    }

    private function idOf(FeaturedItemType $type, mixed $record): int
    {
        return (int) ($type === FeaturedItemType::Contributor ? $record->user_id : $record->getKey());
    }

    private function searchColumn(FeaturedItemType $type): string
    {
        return match ($type) {
            FeaturedItemType::OpenRole, FeaturedItemType::ProofOfWork, FeaturedItemType::Project => $this->table($type).'.title',
            FeaturedItemType::Contributor => 'profiles.headline',
            FeaturedItemType::Organization => 'organizations.name',
            FeaturedItemType::Testimonial => 'testimonials.author_name',
        };
    }

    private function labelOf(FeaturedItemType $type, mixed $record): string
    {
        return match ($type) {
            FeaturedItemType::Contributor => trim(($record->user->name).($record->headline ? ', '.$record->headline : '')),
            FeaturedItemType::Organization => (string) $record->name,
            FeaturedItemType::Testimonial => (string) $record->author_name.' ('.str((string) $record->quote)->limit(40).')',
            FeaturedItemType::Project => $record->code.' '.$record->title,
            default => (string) $record->title,
        };
    }

    private function table(FeaturedItemType $type): string
    {
        return match ($type) {
            FeaturedItemType::OpenRole => 'open_roles',
            FeaturedItemType::Contributor => 'profiles',
            FeaturedItemType::Organization => 'organizations',
            FeaturedItemType::ProofOfWork => 'proof_of_work',
            FeaturedItemType::Testimonial => 'testimonials',
            FeaturedItemType::Project => 'projects',
        };
    }
}
