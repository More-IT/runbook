/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Submission state machine for the Start run dialog (issue #49).
 *
 * The dialog must stay mounted and keep its pending state visible until the
 * run-start request settles: a dismissal (Cancel / close button / Escape /
 * backdrop) while a request is in flight must never make the request look
 * cancelled. This is a pure controller so the transition can be unit tested
 * without a DOM/component harness; the Vue component only mirrors its state and
 * prevents the NcDialog dismissal paths.
 */

export interface StartRunSubmissionOptions {
	/** Perform the run-start request. */
	startRun: () => Promise<{ id: number }>
	/** Called exactly once after a successful start. */
	onStarted: (runId: number) => void
	/** Called when the dialog may genuinely close (never while busy). */
	onClose: () => void
	/** Map an unknown error to a user-facing message. */
	describeError: (error: unknown) => string
	/** Observe the busy flag so a component can render reactively. */
	onBusyChange: (busy: boolean) => void
	/** Observe the current error message (null = none). */
	onErrorChange: (error: string | null) => void
}

export interface StartRunSubmission {
	/** Start the run; never starts a second request while one is pending or after success. */
	submit: () => Promise<void>
	/** Request dismissal; ignored while a request is in flight. */
	requestClose: () => void
	isBusy: () => boolean
	isCompleted: () => boolean
}

/**
 * Create a submission controller for one Start run dialog instance.
 *
 * @param options Request, callbacks and state observers.
 */
export function createStartRunSubmission(options: StartRunSubmissionOptions): StartRunSubmission {
	let busy = false
	let completed = false

	const setBusy = (value: boolean): void => {
		busy = value
		options.onBusyChange(value)
	}
	const setError = (value: string | null): void => options.onErrorChange(value)

	return {
		isBusy: (): boolean => busy,
		isCompleted: (): boolean => completed,
		requestClose(): void {
			// While a request is in flight the dialog must remain open and its
			// pending state visible; the dismissal is ignored, not faked.
			if (busy) {
				return
			}
			options.onClose()
		},
		async submit(): Promise<void> {
			// Centralized re-entry guard: rapid clicks / repeated events only
			// ever produce a single API request, and a completed start never
			// fires a second one.
			if (busy || completed) {
				return
			}
			setBusy(true)
			setError(null)
			try {
				const run = await options.startRun()
				completed = true
				options.onStarted(run.id)
			} catch (error) {
				// Keep the dialog open with the previous form values so the user
				// can retry; only the error message changes.
				setError(options.describeError(error))
			} finally {
				setBusy(false)
			}
		},
	}
}
