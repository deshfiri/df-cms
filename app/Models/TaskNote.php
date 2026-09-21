<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link or a short note shared beside a task's files.
 *
 * Stored exactly as typed. A body that is nothing but a URL is shown as a link;
 * anything else is a note, with any web addresses inside it made clickable.
 */
class TaskNote extends Model
{
    /**
     * Web addresses: http(s):// or www. only — never javascript: or data:, and a
     * bare "report.docx" stays a note rather than becoming https://report.docx.
     */
    private const LONE_URL    = '~^(?:https?://|www\.)[^\s<>"\']+$~i';
    private const URL_IN_TEXT = '~((?:https?://|www\.)[^\s<>"\']+)~i';

    protected $fillable = ['task_id', 'user_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getIsLinkAttribute(): bool
    {
        return (bool) preg_match(self::LONE_URL, trim((string) $this->body));
    }

    /** Where a link points, with https:// added when it was pasted without one. */
    public function getLinkUrlAttribute(): ?string
    {
        return $this->is_link ? self::withScheme(trim((string) $this->body)) : null;
    }

    public function getLinkHostAttribute(): ?string
    {
        $host = $this->link_url ? parse_url($this->link_url, PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./i', '', $host) : null;
    }

    /**
     * The note as HTML: escaped, with its web addresses linked.
     *
     * Split on the addresses first and escape every piece on its own, so no
     * part of what was typed reaches the page unescaped.
     */
    public function getBodyHtmlAttribute(): string
    {
        $html = '';

        foreach (preg_split(self::URL_IN_TEXT, (string) $this->body, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            if ($i % 2 === 0) {
                $html .= e($part);
                continue;
            }

            // "(see https://x.com/a)." — the closing punctuation is the sentence's, not the link's.
            $url   = rtrim($part, '.,;:!?)]}');
            $trail = substr($part, strlen($url));

            $html .= '<a href="' . e(self::withScheme($url)) . '" target="_blank" rel="noopener noreferrer nofollow">' . e($url) . '</a>' . e($trail);
        }

        return $html;
    }

    private static function withScheme(string $url): string
    {
        return preg_match('~^https?://~i', $url) ? $url : 'https://' . $url;
    }
}
