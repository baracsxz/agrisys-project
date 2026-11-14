<?php
if (!function_exists('redirect')) {
    function redirect(string $url): void {
        session_write_close(); //force save session data before redirect
        header("Location: " . $url);
        exit();
    }
}

if (!function_exists('flash')) {
    /**
     * flash('success', 'Saved.') -> queue a message (type = success|danger|warning|info)
     */
    function flash(string $type, string $message = '', string $class = ''): void {
        if ($message !== '') {
            $_SESSION['flash_type']    = ($class !== '') ? $class : $type;
            $_SESSION['flash_message'] = $message;
            return;
        }
    }
}

if (!function_exists('render_flash')) {
    function render_flash(): void {
        if (empty($_SESSION['flash_message'])) {
            return;
        }
        $type    = $_SESSION['flash_type'] ?? 'info';
        $message = $_SESSION['flash_message'];

        // Read once, then clear so it only shows one time
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);

        $icon = match ($type) {
            'success' => 'success',
            'danger'  => 'error',
            'warning' => 'warning',
            default   => 'info',
        };
        
        $alertClass = ($type === 'danger' || $type === 'warning' || $type === 'success') ? $type : 'info';

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $data  = json_encode(['icon' => $icon, 'alert' => $alertClass, 'message' => $message], $flags);

        //self-contained renderer: triggers a sleek SweetAlert toast if available, 
        //or falls back to a clean Bootstrap alert matching your UI design.
        echo "<script>
        (function () {
            var flashData = {$data};
            function showFlash() {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: flashData.icon,
                        title: flashData.message,
                        showConfirmButton: false,
                        timer: 3500,
                        timerProgressBar: true,
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer);
                            toast.addEventListener('mouseleave', Swal.resumeTimer);
                        }
                    });
                } else {
                    var container = document.getElementById('main-content') || document.body;
                    var alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-' + flashData.alert + ' alert-dismissible fade show shadow-sm mt-2';
                    alertDiv.setAttribute('role', 'alert');
                    alertDiv.innerHTML = flashData.message + '<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\" aria-label=\"Close\"></button>';
                    container.insertBefore(alertDiv, container.firstChild);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', showFlash);
            } else {
                showFlash();
            }
        })();
        </script>";
    }
}

//generate or check CSRF token
if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $sent = $_POST['csrf_token'] ?? '';
            $known = $_SESSION['csrf_token'] ?? '';
            if ($known === '' || !is_string($sent) || !hash_equals($known, $sent)) {
                flash('danger', 'Your session expired or the form was invalid. Please try again.');
                redirect($_SERVER['REQUEST_URI'] ?? 'dashboard.php');
            }
        }
    }
}