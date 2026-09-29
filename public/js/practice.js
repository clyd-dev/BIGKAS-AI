/**
 * BIGKAS-AI Practice Session Module
 * Shared driver for all practice types (sight words, phonemic, etc.)
 */
const PracticeSession = (function () {
    let items = [];
    let currentIndex = 0;
    let correctCount = 0;
    let startTime = null;
    let results = [];
    let config = {};

    function init(options) {
        config = options;
        items = options.items || [];
        currentIndex = 0;
        correctCount = 0;
        startTime = Date.now();
        results = [];

        if (config.onStart) config.onStart();
        showItem();
    }

    function showItem() {
        if (currentIndex >= items.length) {
            finish();
            return;
        }

        const item = items[currentIndex];
        if (config.renderItem) {
            config.renderItem(item, currentIndex, items.length);
        }
        updateProgress();
    }

    function recordResponse(correct, data) {
        if (correct) correctCount++;
        results.push({
            item_id: item.id,
            content: item.content,
            correct: correct,
            ...data,
        });
        currentIndex++;
        showItem();
    }

    function updateProgress() {
        if (config.onProgress) {
            config.onProgress(currentIndex, items.length);
        }
    }

    function finish() {
        const elapsed = Math.round((Date.now() - startTime) / 1000);
        const score = items.length > 0 ? Math.round((correctCount / items.length) * 100) : 0;

        if (config.onComplete) {
            config.onComplete({
                score: score,
                correct: correctCount,
                total: items.length,
                time_spent: elapsed,
                items: results,
            });
        }
    }

    return {
        init: init,
        recordResponse: recordResponse,
    };
})();
