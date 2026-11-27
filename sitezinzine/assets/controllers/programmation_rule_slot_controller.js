import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'recurrenceType',
        'monthlyOccurrenceWrapper',
        'monthlyOccurrence',
        'monthIntervalWrapper',
        'monthInterval',
        'weekOffsetWrapper',
        'weekOffset',
    ];

    connect() {
        this.toggleRecurrenceFields();
    }

    toggleRecurrenceFields() {
        const isMonthly = this.recurrenceTypeTarget.value === 'monthly';

        if (this.hasMonthlyOccurrenceWrapperTarget) {
            this.monthlyOccurrenceWrapperTarget.style.display = isMonthly ? 'block' : 'none';
        }

        if (this.hasMonthIntervalWrapperTarget) {
            this.monthIntervalWrapperTarget.style.display = isMonthly ? 'block' : 'none';
        }

        // weekOffset doit rester visible dans les 2 cas
        if (this.hasWeekOffsetWrapperTarget) {
            this.weekOffsetWrapperTarget.style.display = 'block';
        }

        if (isMonthly) {
            if (this.hasMonthlyOccurrenceTarget) {
                this.monthlyOccurrenceTarget.disabled = false;
            }

            if (this.hasMonthIntervalTarget) {
                this.monthIntervalTarget.disabled = false;

                if (!this.monthIntervalTarget.value) {
                    this.monthIntervalTarget.value = '1';
                }
            }

            if (this.hasWeekOffsetTarget) {
                this.weekOffsetTarget.disabled = false;

                if (!this.weekOffsetTarget.value) {
                    this.weekOffsetTarget.value = '0';
                }
            }
        } else {
            if (this.hasMonthlyOccurrenceTarget) {
                this.monthlyOccurrenceTarget.value = '';
                this.monthlyOccurrenceTarget.disabled = true;
            }

            if (this.hasMonthIntervalTarget) {
                this.monthIntervalTarget.value = '1';
                this.monthIntervalTarget.disabled = true;
            }

            if (this.hasWeekOffsetTarget) {
                this.weekOffsetTarget.disabled = false;

                if (!this.weekOffsetTarget.value) {
                    this.weekOffsetTarget.value = '0';
                }
            }
        }
    }
}