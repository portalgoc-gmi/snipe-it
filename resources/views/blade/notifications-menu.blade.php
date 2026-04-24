<li class="dropdown notifications-menu" id="notifications-menu">
    <a href="#" class="dropdown-toggle" data-toggle="dropdown">
        <i class="far fa-bell"></i>
        <span
            class="label label-danger"
            id="notifications-count"
            style="{{ auth()->user()->unreadNotifications()->count() > 0 ? '' : 'display:none;' }}"
        >
            {{ auth()->user()->unreadNotifications()->count() }}
        </span>
    </a>

    <ul class="dropdown-menu">
        <li class="header">
            Notifications
        </li>
        <li>
            <ul class="menu" id="notifications-list">
                @foreach(auth()->user()->unreadNotifications()->take(5)->get() as $notification)
                    <li>
                        @if (($notification->data['type'] ?? null) !== 'asset_declined_recheckout')
                            <a href="{{ route('notifications.open', $notification->id) }}">
                                <strong>{{ $notification->data['title'] ?? 'Notification' }}</strong><br>
                                {{ $notification->data['item_name'] ?? 'Asset' }}<br>
                                <small>{{ $notification->data['requested_by'] ?? 'User' }}</small>
                            </a>
                        @else
                            <div style="padding: 10px 15px;">
                                <strong>{{ $notification->data['title'] ?? 'Notification' }}</strong><br>
                                {{ $notification->data['message'] ?? ($notification->data['item_name'] ?? 'Asset') }}<br>
                                <small>{{ $notification->data['declined_by'] ?? 'User' }}</small>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </li>
        <li class="footer">
            <a href="{{ url('/notifications') }}">View all</a>
        </li>
    </ul>
</li>
    

<script>
function loadNotificationsMenu() {
    fetch("{{ route('notifications.menuData') }}")
        .then(res => res.json())
        .then(data => {
            const menu = document.querySelector('.notifications-menu .menu');
            const count = document.querySelector('.notifications-menu .label');

            if (menu) menu.innerHTML = data.html;

            if (count) {
                if (data.count > 0) {
                    count.innerText = data.count;
                    count.style.display = 'inline';
                } else {
                    count.style.display = 'none';
                }
            }
        });
}

// load κάθε 10 δευτερόλεπτα
setInterval(loadNotificationsMenu, 10000);

// load με το που ανοίξει η σελίδα
document.addEventListener('DOMContentLoaded', loadNotificationsMenu);
</script>
