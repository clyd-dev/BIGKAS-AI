/**
 * BIGKAS-AI Student Portal JavaScript
 * Gamification, celebrations, flash cards, polling
 */

'use strict';

const StudentPortal = {
    csrfToken: '',

    init() {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.initAutoCloseAlerts();
    },

    initAutoCloseAlerts() {
        document.querySelectorAll('.alert-dismissible').forEach(alert => {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            }, 4000);
        });
    },

    // ── AJAX Helper ──
    async post(url, data = {}) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(data),
        });
        return response.json();
    },

    async get(url) {
        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
        });
        return response.json();
    },

    // ── Celebration ──
    celebrate(title, subtitle = '', emoji = '🎉') {
        const overlay = document.getElementById('celebrationOverlay');
        if (!overlay) return;

        overlay.querySelector('.celebration-emoji').textContent = emoji;
        overlay.querySelector('.celebration-title').textContent = title;
        overlay.querySelector('.celebration-subtitle').textContent = subtitle;
        overlay.classList.remove('d-none');

        this.spawnConfetti();
    },

    spawnConfetti() {
        const container = document.getElementById('confettiContainer');
        if (!container) return;
        container.innerHTML = '';

        const colors = ['#FF6584', '#6C63FF', '#FFB830', '#00C897', '#4ECDC4', '#FF8A5C', '#FFD700'];

        for (let i = 0; i < 60; i++) {
            const piece = document.createElement('div');
            piece.className = 'confetti-piece';
            piece.style.left = Math.random() * 100 + 'vw';
            piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            piece.style.animationDuration = (Math.random() * 2 + 1.5) + 's';
            piece.style.animationDelay = Math.random() * 0.8 + 's';
            piece.style.width = (Math.random() * 8 + 6) + 'px';
            piece.style.height = (Math.random() * 8 + 6) + 'px';
            container.appendChild(piece);
        }
    },
};

function dismissCelebration() {
    const overlay = document.getElementById('celebrationOverlay');
    if (overlay) overlay.classList.add('d-none');
}

// ── PIN Input Handler ──
function initPinInput() {
    const digits = document.querySelectorAll('.pin-digit');
    if (!digits.length) return;

    digits.forEach((input, index) => {
        input.addEventListener('input', (e) => {
            const val = e.target.value.replace(/\D/g, '');
            e.target.value = val.slice(0, 1);
            if (val && index < digits.length - 1) {
                digits[index + 1].focus();
            }
            updateHiddenPin();
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && !e.target.value && index > 0) {
                digits[index - 1].focus();
                digits[index - 1].value = '';
                updateHiddenPin();
            }
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            for (let i = 0; i < Math.min(paste.length, digits.length); i++) {
                digits[i].value = paste[i];
            }
            const nextIdx = Math.min(paste.length, digits.length - 1);
            digits[nextIdx].focus();
            updateHiddenPin();
        });
    });

    function updateHiddenPin() {
        const hidden = document.getElementById('pinInput');
        if (hidden) {
            hidden.value = Array.from(digits).map(d => d.value).join('');
        }
    }
}

// ── Flash Card Logic ──
function initFlashCards() {
    const container = document.getElementById('flashcardArea');
    if (!container) return;

    const cardsData = JSON.parse(container.dataset.cards || '[]');
    let currentIndex = 0;
    let correctCount = 0;
    let startTime = Date.now();

    const cardEl = document.getElementById('flashcard');
    const wordEl = document.getElementById('flashcardWord');
    const progressEl = document.getElementById('flashcardProgress');
    const counterEl = document.getElementById('flashcardCounter');

    function showCard() {
        if (currentIndex >= cardsData.length) {
            finishFlashCards();
            return;
        }
        cardEl.classList.remove('flipped');
        wordEl.textContent = cardsData[currentIndex];
        progressEl.style.width = ((currentIndex / cardsData.length) * 100) + '%';
        counterEl.textContent = `${currentIndex + 1} / ${cardsData.length}`;
    }

    window.flashCardKnow = function() {
        correctCount++;
        currentIndex++;
        showCard();
    };

    window.flashCardDontKnow = function() {
        currentIndex++;
        showCard();
    };

    window.flipCard = function() {
        cardEl.classList.toggle('flipped');
    };

    function finishFlashCards() {
        const duration = Math.floor((Date.now() - startTime) / 1000);
        const resultArea = document.getElementById('flashcardResult');
        const area = document.getElementById('flashcardArea');

        if (area) area.classList.add('d-none');
        if (resultArea) {
            resultArea.classList.remove('d-none');
            document.getElementById('resultCorrect').textContent = correctCount;
            document.getElementById('resultTotal').textContent = cardsData.length;
            document.getElementById('resultPercent').textContent = Math.round((correctCount / cardsData.length) * 100) + '%';
        }

        // Save result
        StudentPortal.post(document.getElementById('flashcardSaveUrl')?.value || '/student/flashcards/save', {
            total_cards: cardsData.length,
            correct_cards: correctCount,
            duration_seconds: duration,
        }).then(data => {
            if (data.xp_earned) {
                StudentPortal.celebrate('Practice Complete!', `+${data.xp_earned} XP earned!`, '⭐');
            }
        });
    }

    showCard();
}

// ── Assessment Polling ──
function initAssessmentPolling(sessionId, pollUrl) {
    setInterval(async () => {
        try {
            const data = await StudentPortal.post(pollUrl, {
                progress: {
                    timestamp: Date.now(),
                    active: true,
                }
            });

            const statusEl = document.getElementById('sessionStatus');
            if (statusEl) statusEl.textContent = data.status;

            if (data.status === 'completed') {
                StudentPortal.celebrate('Great Job!', 'You finished reading!', '🌟');
                setTimeout(() => {
                    window.location.href = '/student/dashboard';
                }, 3000);
            }
        } catch (e) {
            console.error('Poll error:', e);
        }
    }, 3000);
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    StudentPortal.init();
    initPinInput();

    if (document.getElementById('flashcardArea')) {
        initFlashCards();
    }
});

window.StudentPortal = StudentPortal;
