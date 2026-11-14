document.addEventListener('DOMContentLoaded', function () {
    //guard: if this file is included twice, only set up once
    if (window.__agrisysInit) return;
    window.__agrisysInit = true;

    //mobile sidebar toggle functionality
    const toggleBtn = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const body = document.body;

    if (toggleBtn && sidebar) {
        function toggleMenu(e) {
            if (e) e.stopPropagation();
            sidebar.classList.toggle('show');
            body.classList.toggle('sidebar-open');
        }

        toggleBtn.addEventListener('click', toggleMenu);

        //close sidebar when clicking outside on the backdrop
        body.addEventListener('click', function(e) {
            if (body.classList.contains('sidebar-open') && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                toggleMenu();
            }
        });
    }

    //mdern sweetalert delete confirmation pop-up
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('delete-btn') || e.target.closest('.delete-btn')) {
            const btn = e.target.closest('.delete-btn');
            const form = btn.closest('form');

            Swal.fire({
                title: 'Are you sure?',
                text: "This action cannot be undone!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        }
    });

    //dynamic expected harvest date calculation (used in plantings module)
    const cropSel = document.getElementById('crop_select');
    const pDate   = document.getElementById('planting_date');
    const eDate   = document.getElementById('expected_harvest_date');

    if (cropSel && pDate && eDate) {
        function updateExpectedDate() {
            const selected = cropSel.options[cropSel.selectedIndex];
            const days = selected ? parseInt(selected.getAttribute('data-days'), 10) : 0;
            if (days > 0 && pDate.value && !eDate.value) {
                const dt = new Date(pDate.value);
                dt.setDate(dt.getDate() + days);
                eDate.value = dt.toISOString().split('T')[0];
            }
        }
        cropSel.addEventListener('change', updateExpectedDate);
        pDate.addEventListener('change', updateExpectedDate);
    }

    // Dynamic farmer contact numbers addition/removal (used in farmers module)
    const addContactBtn = document.getElementById('add-contact-btn');
    const contactList = document.getElementById('contact-list');

    if (addContactBtn && contactList) {
        addContactBtn.addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'input-group mb-2 contact-row';
            row.innerHTML = `
                <input type="text" name="contacts[]" class="form-control" placeholder="Enter phone number">
                <button type="button" class="btn btn-outline-danger remove-contact-btn" title="Remove">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            contactList.appendChild(row);
        });

        contactList.addEventListener('click', function (e) {
            if (e.target.closest('.remove-contact-btn')) {
                const row = e.target.closest('.contact-row');
                if (document.querySelectorAll('.contact-row').length > 1) {
                    row.remove();
                }
            }
        });
    }
});