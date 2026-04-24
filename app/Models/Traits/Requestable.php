<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\Auth;
use App\Models\CheckoutRequest;
use App\Models\User;

// $asset->requests
// $asset->isRequestedBy($user)
// $asset->whereRequestedBy($user)
trait Requestable
{
    public function requests()
    {
        return $this->morphMany(CheckoutRequest::class, 'requestable');
    }

    public function isRequestedBy(User $user)
	{
	    $query = $this->requests()
		->whereNull('canceled_at')
		->where('user_id', $user->id);

	    if (\Illuminate\Support\Facades\Schema::hasColumn('checkout_requests', 'fulfilled_at')) {
		$query->whereNull('fulfilled_at');
	    }

	    if (\Illuminate\Support\Facades\Schema::hasColumn('checkout_requests', 'checked_out_at')) {
		$query->whereNull('checked_out_at');
	    }

	    return $query->latest()->first();
	}

    public function scopeRequestedBy($query, User $user)
    {
        return $query->whereHas(
            'requests', function ($query) use ($user) {
                $query->where('user_id', $user->id);
            }
        );
    }

    public function request($qty = 1)
    {
        $this->requests()->save(
            new CheckoutRequest(['user_id' => auth()->id(), 'qty' => $qty])
        );
    }

    public function deleteRequest()
    {
        $this->requests()->where('user_id', auth()->id())->delete();
    }

    public function cancelRequest($user_id = null)
    {
        if (!$user_id) {
            $user_id = auth()->id();
        }

        $this->requests()->where('user_id', $user_id)->update(['canceled_at' => \Carbon\Carbon::now()]);
    }
}
