@if(session('success'))
<div data-hgt-flash="success" hidden>{{ session('success') }}</div>
@endif
@if(session('info'))
<div data-hgt-flash="info" hidden>{{ session('info') }}</div>
@endif
@if(session('error'))
<div data-hgt-flash="error" hidden>{{ session('error') }}</div>
@endif
