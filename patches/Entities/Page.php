<?php

namespace BookStack\Entities\Models;

use BookStack\Entities\Tools\PageContent;
use BookStack\Permissions\PermissionApplicator;
use BookStack\Uploads\Attachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Class Page.
 * @property EntityPageData $pageData
 * @property int          $chapter_id
 * @property string       $html
 * @property string       $markdown
 * @property string       $text
 * @property bool         $template
 * @property bool         $draft
 * @property int          $revision_count
 * @property string       $editor
 * @property Chapter|null $chapter
 * @property Collection   $attachments
 * @property Collection   $revisions
 * @property PageRevision $currentRevision
 */
class Page extends BookChild
{
    use HasFactory;

    public string $textField = 'text';
    public string $htmlField = 'html';
    protected $hidden = ['html', 'markdown', 'text', 'pivot', 'deleted_at',  'entity_id', 'entity_type'];
    protected $fillable = ['name', 'priority'];

    protected $casts = [
        'draft'    => 'boolean',
        'template' => 'boolean',
    ];

    /**
     * Get the entities that are visible to the current user.
     */
    public function scopeVisible(Builder $query): Builder
    {
        $query = app()->make(PermissionApplicator::class)->restrictDraftsOnPageQuery($query);

        return parent::scopeVisible($query);
    }

    /**
     * Get the chapter that this page is in, If applicable.
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Check if this page has a chapter.
     */
    public function hasChapter(): bool
    {
        return $this->chapter()->count() > 0;
    }

    /**
     * Get the associated page revisions, ordered by created date.
     * Only provides actual saved page revision instances, Not drafts.
     */
    public function revisions(): HasMany
    {
        return $this->allRevisions()
            ->where('type', '=', 'version')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Get the current revision for the page if existing.
     */
    public function currentRevision(): HasOne
    {
        return $this->hasOne(PageRevision::class)
            ->where('type', '=', 'version')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Get all revision instances assigned to this page.
     * Includes all types of revisions.
     */
    public function allRevisions(): HasMany
    {
        return $this->hasMany(PageRevision::class);
    }

    /**
     * Get the attachments assigned to this page.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'uploaded_to')->orderBy('order', 'asc');
    }

    /**
     * Get the url of this page.
     */
    public function getUrl(string $path = ''): string
    {
        $parts = [
            'books',
            urlencode($this->book_slug ?? $this->book->slug),
            $this->draft ? 'draft' : 'page',
            $this->draft ? $this->id : urlencode($this->slug),
            trim($path, '/'),
        ];

        return url('/' . implode('/', $parts));
    }

    /**
     * Get the ID-based permalink for this page.
     */
    public function getPermalink(): string
    {
        return url("/link/{$this->id}");
    }

    /**
     * Get this page for JSON display.
     */
    public function forJsonDisplay(): self
    {
        $refreshed = $this->refresh()->unsetRelations()->load(['tags', 'createdBy', 'updatedBy', 'ownedBy']);
        $refreshed->setHidden(array_diff($refreshed->getHidden(), ['html', 'markdown']));
        $refreshed->setAttribute('raw_html', $refreshed->html);
        $refreshed->setAttribute('html', (new PageContent($refreshed))->render());

        return $refreshed;
    }

    /**
     * @return HasOne<EntityPageData, $this>
     */
    public function relatedData(): HasOne
    {
        return $this->hasOne(EntityPageData::class, 'page_id', 'id');
    }

    /**
     * lucos patch (lucas42/lucos_worlds#52): a page has no manually-authored
     * description like Book/Chapter/Bookshelf do — Entity::getExcerpt() (the
     * upstream implementation this overrides) just takes the first $length
     * characters of the page's auto-derived plain-text `text` field. Since
     * HtmlToPlainText puts exactly one newline between block-level elements,
     * a page written as a short summary paragraph followed immediately by a
     * list or table gets that list/table content bled into the excerpt,
     * which reads as thin or garbled.
     *
     * Stopping at the first newline (if any) before applying the existing
     * length truncation means an author who opens a page with a short
     * paragraph gets exactly that paragraph as the description, with no
     * bleed-through from whatever follows it. This intentionally only
     * overrides Page — Book/Chapter/Bookshelf keep the base Entity
     * implementation, since their `description` field is manually authored
     * content where an existing multi-line description is deliberate, not an
     * artifact of auto-derivation.
     */
    public function getExcerpt(int $length = 100): string
    {
        $text = trim($this->text ?? '');
        $firstLine = strstr($text, "\n", true);
        if ($firstLine !== false && $firstLine !== '') {
            $text = $firstLine;
        }

        if (mb_strlen($text) > $length) {
            $text = mb_substr($text, 0, $length - 3) . '...';
        }

        return trim($text);
    }
}
