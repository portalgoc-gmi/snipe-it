<li class="dropdown notifications-menu">
    <a href="#" class="dropdown-toggle" data-toggle="dropdown">
        <i class="far fa-bell"></i>
        
	@if(auth()->user()->unreadNotifications()->count() > 0)
	<span class="label label-danger">
	    {{ auth()->user()->unreadNotifications()->count() }}
	</span>
	@endif
    </a>

    <ul class="dropdown-menu">
        <li class="header">
            Notifications
        </li>

        <li>
            <ul class="menu">
                @foreach(auth()->user()->unreadNotifications()->take(5)->get() as $notification)
		<li>
		    <a href="{{ route('notifications.open', $notification->id) }}">
		    <strong>{{ $notification->data['title'] ?? 'Notification' }}</strong><br>
		    {{ $notification->data['item_name'] ?? 'Asset' }}<br>
		    <small>{{ $notification->data['requested_by'] ?? 'User' }}</small>
		    </a>
		</li>
		@endforeach
            </ul>
        </li>

        <li class="footer">
            <a href="{{ url('/notifications') }}">View all</a>
        </li>
    </ul>
</li>
