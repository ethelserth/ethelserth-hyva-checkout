/**
 * Alpine.store('checkout') — step state manager.
 *
 * Visual-only: knows which step is active, done, or locked.
 * Never touches quote data — that's Magewire's job.
 */
document.addEventListener('alpine:init', () => {
    Alpine.store('checkout', {
        /** @type {Array<{name: string, label: string, order: number, unlockOn: string|null}>} */
        steps: [],
        currentStep: null,
        /** @type {string[]} */
        doneSteps: [],

        setup(steps) {
            this.steps = steps;
            this.currentStep = steps[0]?.name ?? null;
        },

        isActive(name) {
            return this.currentStep === name;
        },

        isDone(name) {
            return this.doneSteps.includes(name);
        },

        isLocked(name) {
            const step = this.steps.find(s => s.name === name);
            if (!step?.unlockOn) return false;
            // Locked until the step that emits the required event is done.
            // Convention: step name matches the camelCase prefix of the event.
            // e.g. unlockOn="addressSaved" → step "address" must be done.
            const emittingStep = this.steps.find(s =>
                s.unlockOn && step.unlockOn !== s.unlockOn &&
                step.unlockOn.toLowerCase().startsWith(s.name.toLowerCase())
            ) ?? this.steps.find(s => !s.unlockOn && this.steps.indexOf(s) === this.steps.indexOf(step) - 1);
            if (!emittingStep) return false;
            return !this.isDone(emittingStep.name);
        },

        advance(from, to) {
            if (!this.doneSteps.includes(from)) {
                this.doneSteps.push(from);
            }
            this.currentStep = to;
            this._scrollToStep(to);
        },

        reopenStep(name) {
            const idx = this.steps.findIndex(s => s.name === name);
            const toRemove = this.steps.slice(idx).map(s => s.name);
            this.doneSteps = this.doneSteps.filter(n => !toRemove.includes(n));
            this.currentStep = name;
            this._scrollToStep(name);
        },

        _scrollToStep(name) {
            const el = document.getElementById('checkout-step-' + name);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        },
    });
});
