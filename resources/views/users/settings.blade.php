@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <!-- Page Title -->
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">{{ $user->name }} - Profile Settings</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Users</a></li>
                                <li class="breadcrumb-item active">Profile Settings</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Flash Messages -->
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="ri-check-line me-2"></i> {{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="ri-error-warning-line me-2"></i> {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="ri-error-warning-line me-2"></i>
                    <ul class="mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            @endif

            <div class="row">
                <div class="col-xxl-12">

                    <!-- Tabs Navigation -->
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-4">
                        <ul class="nav nav-pills arrow-navtabs nav-secondary gap-2 flex-grow-1" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#personalDetails">
                                    <i class="ri-user-line me-1"></i> Personal Details
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-bs-toggle="tab" href="#security">
                                    <i class="ri-lock-line me-1"></i> Security
                                </a>
                            </li>
                        </ul>
                        @can('View user')
                        <div class="flex-shrink-0 ms-auto">
                            <a href="{{ route('users.index') }}" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Users
                            </a>
                        </div>
                        @endcan
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="tab-content">

                                <!-- Personal Details Tab -->
                                <div class="tab-pane active" id="personalDetails" role="tabpanel">
                                    <div class="text-center mb-5">
                                        <div class="position-relative d-inline-block">
                                            <div class="avatar-xxl">
                                                @php
                                                    $avatarUrl = $user->avatar_url;
                                                    $hasAvatar = (bool) $user->avatar;
                                                    $initials  = $user->initials;
                                                @endphp

                                                @if($hasAvatar)
                                                    <img src="{{ $avatarUrl }}?t={{ time() }}"
                                                         alt="Profile"
                                                         class="rounded-circle img-thumbnail"
                                                         id="profilePreview"
                                                         style="width: 150px; height: 150px; object-fit: cover;"
                                                         onerror="this.onerror=null; this.src='{{ $user->fallback_avatar_url }}';">
                                                @else
                                                    <div class="avatar-title rounded-circle bg-light text-primary fs-1"
                                                         style="width: 150px; height: 150px; line-height: 150px;"
                                                         id="profilePreview">
                                                        {{ $initials }}
                                                    </div>
                                                @endif
                                            </div>

                                            @if($canEdit)
                                                <label for="avatar" class="position-absolute bottom-0 end-0 btn btn-sm btn-icon btn-primary rounded-circle">
                                                    <i class="ri-camera-line fs-16"></i>
                                                    <input type="file" id="avatar" name="avatar" class="d-none" accept="image/*">
                                                </label>
                                            @endif
                                        </div>
                                        <h4 class="mt-3 mb-1">{{ $user->name }}</h4>
                                        <p class="text-muted mb-0">{{ $user->email }}</p>
                                        <div class="mt-2">
                                            @foreach($user->roles as $role)
                                                <span class="badge bg-info me-1">{{ $role->name }}</span>
                                            @endforeach
                                        </div>
                                    </div>

                                    <!-- Personal Details Content -->
                                    @php $dob = $user->date_of_birth?->format('Y-m-d'); @endphp
                                    <form action="{{ route('profile.update-info') }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $user->id }}">
                                        <fieldset @disabled(! $canEdit)>
                                        <div class="row g-3">
                                            <div class="col-12"><h5 class="mb-3 border-bottom pb-2">Personal Information</h5></div>
                                            <div class="col-md-6">
                                                <label>Full Name <span class="text-danger">*</span></label>
                                                <input type="text" name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Username</label>
                                                <input type="text" name="username" class="form-control" value="{{ old('username', $user->username) }}">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Phone Number</label>
                                                <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number', $user->phone_number) }}">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Gender</label>
                                                <select name="gender" class="form-control">
                                                    <option value="">Select</option>
                                                    @foreach(['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $val => $label)
                                                        <option value="{{ $val }}" @selected(old('gender', $user->gender) === $val)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label>Nationality</label>
                                                <input type="text" name="nationality" class="form-control" value="{{ old('nationality', $user->nationality) }}">
                                            </div>
                                            <div class="col-md-6">
                                                <label>Date of Birth</label>
                                                <input type="date" name="date_of_birth" class="form-control" value="{{ old('date_of_birth', $dob) }}">
                                            </div>
                                            <div class="col-12">
                                                <label>Address</label>
                                                <textarea name="address" class="form-control" rows="3">{{ old('address', $user->address) }}</textarea>
                                            </div>
                                            @if($canEdit)
                                            <div class="col-12 text-end mt-4">
                                                <button type="reset" class="btn btn-light me-2">Reset</button>
                                                <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i> Save Changes</button>
                                            </div>
                                            @endif
                                        </div>
                                        </fieldset>
                                    </form>
                                </div>

                                <!-- Security Tab -->
                                <div class="tab-pane" id="security" role="tabpanel">
                                    <div class="row">
                                        <div class="col-lg-6 mb-4">
                                            <div class="card border">
                                                <div class="card-header bg-light"><h5 class="card-title mb-0"><i class="ri-mail-line me-2"></i>Update Email</h5></div>
                                                <div class="card-body">
                                                    @if($canEdit)
                                                        <form id="updateEmailForm">
                                                            @csrf
                                                            <input type="hidden" name="userid" value="{{ $user->id }}">
                                                            <div class="mb-3"><label>Current Email</label><input type="email" class="form-control" value="{{ $user->email }}" readonly></div>
                                                            <div class="mb-3"><label>New Email *</label><input type="email" name="emailaddress" class="form-control" required></div>
                                                            <div class="mb-3"><label>Confirm New Email *</label><input type="email" name="emailaddress_confirmation" class="form-control" required></div>
                                                            <button type="submit" class="btn btn-primary"><span class="spinner-border spinner-border-sm d-none me-1"></span> Update Email</button>
                                                        </form>
                                                        <div id="emailMessage" class="mt-3"></div>
                                                    @else
                                                        <p><strong>Current Email:</strong> {{ $user->email }}</p>
                                                        <small class="text-muted">Ask an administrator to change this email address.</small>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-lg-6 mb-4">
                                            <div class="card border">
                                                <div class="card-header bg-light"><h5 class="card-title mb-0"><i class="ri-lock-line me-2"></i>Change Password</h5></div>
                                                <div class="card-body">
                                                    @if($canEdit)
                                                        <form id="updatePasswordForm">
                                                            @csrf
                                                            <input type="hidden" name="userid" value="{{ $user->id }}">
                                                            <div class="mb-3"><label>New Password *</label><input type="password" name="password" class="form-control" required></div>
                                                            <div class="mb-3"><label>Confirm Password *</label><input type="password" name="password_confirmation" class="form-control" required></div>
                                                            <div class="alert alert-info"><i class="ri-information-line me-2"></i> Minimum 8 characters</div>
                                                            <button type="submit" class="btn btn-primary"><span class="spinner-border spinner-border-sm d-none me-1"></span> Update Password</button>
                                                        </form>
                                                        <div id="passwordMessage" class="mt-3"></div>
                                                    @else
                                                        <p><strong>Password Status:</strong> Set</p>
                                                        <small class="text-muted">Ask an administrator to reset this password.</small>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script>
// Session messages
@if(session('success'))
    Swal.fire({
        icon: 'success',
        title: 'Success',
        text: @json(session('success')),
        toast: true,
        position: 'top-end',
        timer: 3000,
        showConfirmButton: false
    });
@endif
@if(session('error'))
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: @json(session('error')),
        toast: true,
        position: 'top-end',
        timer: 3000,
        showConfirmButton: false
    });
@endif

// Avatar upload
@if($canEdit)
document.getElementById('avatar')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        Swal.fire('Error', 'Image must be less than 5MB', 'error');
        this.value = '';
        return;
    }

    if (!file.type.match('image.*')) {
        Swal.fire('Error', 'Please select an image file', 'error');
        this.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('profilePreview');
        const fallback = document.getElementById('profileFallback') || null;

        if (preview.tagName === 'IMG') {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            if (fallback) fallback.classList.add('d-none');
        } else {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.alt = 'Profile';
            img.className = 'rounded-circle img-thumbnail';
            img.id = 'profilePreview';
            img.style.cssText = 'width: 150px; height: 150px; object-fit: cover;';
            img.onerror = function() {
                this.onerror = null;
                this.src = '{{ $user->fallback_avatar_url }}';
            };
            preview.parentNode.replaceChild(img, preview);
        }
    };
    reader.readAsDataURL(file);

    const fd = new FormData();
    fd.append('avatar', file);
    fd.append('id', '{{ $user->id }}');
    fd.append('_token', '{{ csrf_token() }}');

    Swal.fire({
        title: 'Uploading...',
        html: 'Please wait while we upload your profile picture',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });

    fetch('{{ route("profile.update-avatar") }}', {
        method: 'POST',
        body: fd,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });

            if (data.avatar_url) {
                document.getElementById('profilePreview').src = data.avatar_url + '?t=' + Date.now();
            }
        } else {
            Swal.fire('Error!', data.message || 'Failed to upload avatar', 'error');
        }
    })
    .catch(error => {
        Swal.close();
        Swal.fire('Error!', 'Network error occurred. Please try again.', 'error');
    });
});
@endif

// Email Update Form
@if($canEdit)
document.getElementById('updateEmailForm')?.addEventListener('submit', function(e) {
    e.preventDefault();

    const form = this;
    const fd = new FormData(form);
    const btn = form.querySelector('button[type="submit"]');
    const spinner = btn.querySelector('.spinner-border');
    const msgDiv = document.getElementById('emailMessage');

    btn.disabled = true;
    if (spinner) spinner.classList.remove('d-none');

    fetch('{{ route("profile.update-email") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: fd
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });
            msgDiv.innerHTML = `<div class="alert alert-success alert-dismissible fade show mt-3">
                <i class="ri-check-line me-2"></i>${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
            form.reset();
        } else {
            Swal.fire('Error!', data.message || 'Failed to update email', 'error');
            msgDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show mt-3">
                <i class="ri-error-warning-line me-2"></i>${data.message || 'An error occurred'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
        }
    })
    .catch(error => {
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
        msgDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show mt-3">
            <i class="ri-error-warning-line me-2"></i>Request failed. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`;
    })
    .finally(() => {
        btn.disabled = false;
        if (spinner) spinner.classList.add('d-none');
    });
});
@endif

// Password Update Form
@if($canEdit)
document.getElementById('updatePasswordForm')?.addEventListener('submit', function(e) {
    e.preventDefault();

    const form = this;
    const fd = new FormData(form);
    const btn = form.querySelector('button[type="submit"]');
    const spinner = btn.querySelector('.spinner-border');
    const msgDiv = document.getElementById('passwordMessage');

    btn.disabled = true;
    if (spinner) spinner.classList.remove('d-none');

    fetch('{{ route("profile.update-password") }}', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: fd
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                timer: 2000,
                showConfirmButton: false
            });
            msgDiv.innerHTML = `<div class="alert alert-success alert-dismissible fade show mt-3">
                <i class="ri-check-line me-2"></i>${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
            form.reset();
        } else {
            Swal.fire('Error!', data.message || 'Failed to update password', 'error');
            msgDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show mt-3">
                <i class="ri-error-warning-line me-2"></i>${data.message || 'An error occurred'}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
        }
    })
    .catch(error => {
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
        msgDiv.innerHTML = `<div class="alert alert-danger alert-dismissible fade show mt-3">
            <i class="ri-error-warning-line me-2"></i>Request failed. Please try again.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`;
    })
    .finally(() => {
        btn.disabled = false;
        if (spinner) spinner.classList.add('d-none');
    });
});
@endif

// Tab persistence (remember last active tab)
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', e => {
            localStorage.setItem('activeProfileTab', e.target.getAttribute('href'));
        });
    });

    const saved = localStorage.getItem('activeProfileTab');
    if (saved) {
        const tab = document.querySelector(`a[href="${saved}"]`);
        if (tab) {
            const bsTab = new bootstrap.Tab(tab);
            bsTab.show();
        }
    }
});
</script>
@endsection
