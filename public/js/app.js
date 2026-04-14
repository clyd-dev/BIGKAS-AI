/**
 * BIGKAS-AI - Application JavaScript
 * General frontend utilities and shared functionality
 */

'use strict';

const BigkasAI = {
    /**
     * Initialize the application
     */
    init() {
        this.initActiveNavLink();
        this.initTooltips();
        this.initAutoCloseAlerts();
        this.initConfirmDialogs();
        this.initDeleteForms();
        this.initSearchFilter();
        this.initBackToTop();
    },

    /**
     * Highlight the current nav link based on URL
     */
    initActiveNavLink() {
        const currentPath = window.location.pathname;
        document.querySelectorAll('.navbar .nav-link').forEach(link => {
            const href = link.getAttribute('href');
            if (href && href !== '/' && currentPath.startsWith(href)) {
                link.classList.add('active');
            } else if (href === '/dashboard' && currentPath === '/dashboard') {
                link.classList.add('active');
            }
        });
    },

    /**
     * Initialize Bootstrap tooltips
     */
    initTooltips() {
        const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltips.forEach(el => new bootstrap.Tooltip(el));
    },

    /**
     * Auto-close alerts after 5 seconds
     */
    initAutoCloseAlerts() {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) {
                    bsAlert.close();
                }
            }, 5000);
        });
    },

    /**
     * Confirm before dangerous actions
     */
    initConfirmDialogs() {
        document.querySelectorAll('[data-confirm]').forEach(el => {
            el.addEventListener('click', function (e) {
                const message = this.getAttribute('data-confirm') || 'Are you sure?';
                if (!confirm(message)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        });
    },

    /**
     * Handle delete forms with PUT/DELETE method spoofing (Laravel convention)
     */
    initDeleteForms() {
        document.querySelectorAll('.btn-delete').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const name = this.getAttribute('data-name') || 'this item';
                const url = this.getAttribute('data-url') || this.getAttribute('href');

                if (confirm(`Are you sure you want to delete ${name}? This action cannot be undone.`)) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = url;

                    // CSRF token (Laravel convention: _token)
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = '_token';
                    csrfInput.value = BigkasAI.getCsrfToken();
                    form.appendChild(csrfInput);

                    // Method spoofing (Laravel convention: _method)
                    const methodInput = document.createElement('input');
                    methodInput.type = 'hidden';
                    methodInput.name = '_method';
                    methodInput.value = 'DELETE';
                    form.appendChild(methodInput);

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    },

    /**
     * Client-side search/filter for tables and lists
     */
    initSearchFilter() {
        const searchInput = document.getElementById('searchFilter');
        if (!searchInput) return;

        const targetId = searchInput.getAttribute('data-target');
        const target = document.getElementById(targetId);
        if (!target) return;

        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            const items = target.querySelectorAll('[data-searchable]');

            items.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(query) ? '' : 'none';
            });

            // Update count if there's an element for it
            const countEl = document.getElementById('searchCount');
            if (countEl) {
                const visible = target.querySelectorAll('[data-searchable]:not([style*="display: none"])').length;
                countEl.textContent = visible;
            }
        });
    },

    /**
     * Back to top button
     */
    initBackToTop() {
        const btn = document.getElementById('backToTop');
        if (!btn) return;

        window.addEventListener('scroll', () => {
            btn.style.display = window.scrollY > 300 ? 'block' : 'none';
        });

        btn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    },

    /**
     * Get CSRF token from meta tag (Laravel convention)
     */
    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');

        const input = document.querySelector('input[name="_token"]');
        if (input) return input.value;

        return '';
    },

    /**
     * Make an AJAX request (with Laravel CSRF header)
     */
    async ajax(url, options = {}) {
        const defaults = {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };

        // Add CSRF token for non-GET requests (Laravel uses X-CSRF-TOKEN header)
        if (options.method && options.method !== 'GET') {
            defaults.headers['X-CSRF-TOKEN'] = this.getCsrfToken();
        }

        const config = { ...defaults, ...options };
        config.headers = { ...defaults.headers, ...(options.headers || {}) };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok) {
                throw { status: response.status, data };
            }

            return data;
        } catch (error) {
            if (error.data) throw error;
            throw { status: 0, data: { message: 'Network error. Please try again.' } };
        }
    },

    /**
     * Show a toast notification
     */
    showToast(message, type = 'info') {
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }

        const icons = {
            success: 'bi-check-circle-fill text-success',
            error: 'bi-exclamation-triangle-fill text-danger',
            warning: 'bi-exclamation-circle-fill text-warning',
            info: 'bi-info-circle-fill text-info'
        };

        const toast = document.createElement('div');
        toast.className = 'toast show';
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="toast-header">
                <i class="bi ${icons[type] || icons.info} me-2"></i>
                <strong class="me-auto">${type.charAt(0).toUpperCase() + type.slice(1)}</strong>
                <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">${this.escapeHtml(message)}</div>
        `;

        container.appendChild(toast);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 5000);

        // Close button
        toast.querySelector('.btn-close').addEventListener('click', () => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        });
    },

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Format date string
     */
    formatDate(dateStr, format = 'short') {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return dateStr;

        const options = format === 'long'
            ? { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }
            : { year: 'numeric', month: 'short', day: 'numeric' };

        return date.toLocaleDateString('en-US', options);
    },

    /**
     * Loading overlay control
     */
    loading: {
        show(message = 'Processing...') {
            let overlay = document.getElementById('loading-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'loading-overlay';
                overlay.className = 'loading-overlay';
                overlay.innerHTML = `
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p id="loading-message">${BigkasAI.escapeHtml(message)}</p>
                `;
                document.body.appendChild(overlay);
            } else {
                const msg = overlay.querySelector('#loading-message');
                if (msg) msg.textContent = message;
                overlay.style.display = 'flex';
            }
        },

        hide() {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) {
                overlay.style.display = 'none';
            }
        }
    },

    /**
     * Debounce utility
     */
    debounce(func, wait = 300) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    BigkasAI.init();
});

// Expose globally
window.BigkasAI = BigkasAI;
