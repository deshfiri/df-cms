<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer on WhatsApp.
 *
 * One record per person across every brand — the same human writing to two of our
 * numbers is one contact with two conversations, which is the only arrangement
 * that can ever answer "has this customer contacted us before?".
 */
class WhatsAppContact extends Model
{
    /** Explicit: the convention would look for `whats_app_contacts`. */
    protected $table = 'whatsapp_contacts';

    protected $fillable = ['wa_id', 'phone', 'profile_name', 'name', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * What to call them.
     *
     * A name we have set wins over the one they set on their own profile, which
     * in turn beats showing a bare phone number.
     */
    public function displayName(): string
    {
        return $this->name
            ?: $this->profile_name
            ?: $this->formattedPhone();
    }

    public function formattedPhone(): string
    {
        $number = $this->phone ?: $this->wa_id;

        return $number ? '+' . ltrim($number, '+') : '—';
    }

    /**
     * Reduce anything Meta or a human might give us to bare digits.
     *
     * wa_id already arrives in this shape; a number typed by an agent does not.
     */
    public static function normalisePhone(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        return $digits !== '' ? $digits : null;
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'whatsapp_contact_id');
    }
}
