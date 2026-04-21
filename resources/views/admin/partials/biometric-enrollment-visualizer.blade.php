@php($visualizerModalId = $modalId ?? 'biometricEnrollmentVisualizerModal')

<div class="modal fade" id="{{ $visualizerModalId }}" tabindex="-1" aria-labelledby="{{ $visualizerModalId }}Label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <div>
                    <div class="small text-uppercase fw-semibold text-primary" data-visualizer-phase-label>Waiting For Device</div>
                    <h5 class="modal-title mb-0" id="{{ $visualizerModalId }}Label" data-visualizer-title>Biometric Enrollment</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-3">
                <div class="biometric-visualizer-stage mb-4">
                    <div class="biometric-visualizer-halo"></div>
                    <div class="biometric-visualizer-scanline"></div>
                    <div class="biometric-visualizer-core">
                        <i class="bi bi-fingerprint" data-visualizer-icon></i>
                    </div>
                </div>

                <div class="text-center mb-4">
                    <div class="fw-semibold fs-5" data-visualizer-message>Queueing the enrollment command...</div>
                    <div class="text-muted mt-2" data-visualizer-meta>
                        The terminal will start the guided capture as soon as it polls ADMS.
                    </div>
                </div>

                <div class="progress biometric-visualizer-progress-shell mb-3" role="progressbar" aria-label="Enrollment progress">
                    <div
                        class="progress-bar biometric-visualizer-progress"
                        data-visualizer-progress
                        style="width: 0%"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="0"
                    ></div>
                </div>

                <div class="alert alert-danger d-none" data-visualizer-error></div>

                <div class="biometric-visualizer-steps" data-visualizer-steps></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <div class="text-muted small me-auto" data-visualizer-footer>
                    Keep the employee in front of the selected device until the capture finishes.
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@once
    @push('extra_css')
        <style>
            .biometric-visualizer-stage {
                position: relative;
                width: min(240px, 100%);
                aspect-ratio: 1;
                margin: 0 auto;
                display: grid;
                place-items: center;
                border-radius: 32px;
                overflow: hidden;
                background:
                    radial-gradient(circle at top, rgba(13, 110, 253, 0.25), transparent 55%),
                    linear-gradient(145deg, #eef5ff 0%, #dcecff 48%, #f9fbff 100%);
                box-shadow:
                    inset 0 1px 0 rgba(255, 255, 255, 0.8),
                    0 20px 45px rgba(13, 110, 253, 0.18);
            }

            .biometric-visualizer-stage::before,
            .biometric-visualizer-stage::after {
                content: '';
                position: absolute;
                inset: 18px;
                border-radius: 28px;
                border: 1px solid rgba(13, 110, 253, 0.16);
            }

            .biometric-visualizer-stage::after {
                inset: 34px;
                border-color: rgba(13, 110, 253, 0.12);
            }

            .biometric-visualizer-halo {
                position: absolute;
                width: 62%;
                height: 62%;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(13, 110, 253, 0.2) 0%, rgba(13, 110, 253, 0.03) 60%, transparent 100%);
                animation: biometricVisualizerPulse 2.4s ease-in-out infinite;
            }

            .biometric-visualizer-core {
                position: relative;
                z-index: 2;
                width: 116px;
                height: 116px;
                border-radius: 32px;
                display: grid;
                place-items: center;
                color: #0d6efd;
                background: rgba(255, 255, 255, 0.86);
                border: 1px solid rgba(13, 110, 253, 0.14);
                backdrop-filter: blur(10px);
                box-shadow: 0 10px 25px rgba(13, 110, 253, 0.14);
            }

            .biometric-visualizer-core i {
                font-size: 3.2rem;
                line-height: 1;
            }

            .biometric-visualizer-scanline {
                position: absolute;
                left: 18%;
                right: 18%;
                height: 4px;
                border-radius: 999px;
                background: linear-gradient(90deg, rgba(13, 110, 253, 0), rgba(13, 110, 253, 0.95), rgba(13, 110, 253, 0));
                box-shadow: 0 0 18px rgba(13, 110, 253, 0.55);
                animation: biometricVisualizerScan 2.2s linear infinite;
                z-index: 1;
            }

            .biometric-visualizer-progress-shell {
                height: 12px;
                border-radius: 999px;
                background: #e8eef8;
            }

            .biometric-visualizer-progress {
                border-radius: 999px;
                background: linear-gradient(90deg, #0d6efd 0%, #20c997 100%);
                transition: width 0.35s ease;
            }

            .biometric-visualizer-steps {
                display: grid;
                gap: 0.85rem;
            }

            .biometric-visualizer-step {
                display: flex;
                gap: 0.9rem;
                align-items: flex-start;
                padding: 0.9rem 1rem;
                border-radius: 18px;
                background: #f8fbff;
                border: 1px solid #e0ebff;
                transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
            }

            .biometric-visualizer-step.is-active {
                border-color: rgba(13, 110, 253, 0.35);
                box-shadow: 0 14px 26px rgba(13, 110, 253, 0.09);
                transform: translateY(-2px);
            }

            .biometric-visualizer-step.is-completed {
                background: #f4fffb;
                border-color: rgba(25, 135, 84, 0.22);
            }

            .biometric-visualizer-step.is-error {
                background: #fff7f7;
                border-color: rgba(220, 53, 69, 0.24);
            }

            .biometric-visualizer-step-icon {
                width: 2.15rem;
                height: 2.15rem;
                flex: 0 0 2.15rem;
                display: grid;
                place-items: center;
                border-radius: 50%;
                background: #dce9ff;
                color: #0d6efd;
                font-size: 0.95rem;
                box-shadow: inset 0 0 0 1px rgba(13, 110, 253, 0.1);
            }

            .biometric-visualizer-step.is-completed .biometric-visualizer-step-icon {
                background: rgba(25, 135, 84, 0.14);
                color: #198754;
            }

            .biometric-visualizer-step.is-error .biometric-visualizer-step-icon {
                background: rgba(220, 53, 69, 0.12);
                color: #dc3545;
            }

            .biometric-visualizer-step-title {
                font-weight: 600;
                color: #1f2a44;
            }

            .biometric-visualizer-step-description {
                color: #6c757d;
                font-size: 0.92rem;
            }

            .modal[data-biometric-type="face"] .biometric-visualizer-stage {
                background:
                    radial-gradient(circle at top, rgba(32, 201, 151, 0.22), transparent 55%),
                    linear-gradient(145deg, #effff9 0%, #e0faf1 52%, #f8fffc 100%);
                box-shadow:
                    inset 0 1px 0 rgba(255, 255, 255, 0.82),
                    0 20px 45px rgba(32, 201, 151, 0.16);
            }

            .modal[data-biometric-type="face"] .biometric-visualizer-stage::before,
            .modal[data-biometric-type="face"] .biometric-visualizer-stage::after,
            .modal[data-biometric-type="face"] .biometric-visualizer-core {
                border-color: rgba(32, 201, 151, 0.18);
            }

            .modal[data-biometric-type="face"] .biometric-visualizer-core,
            .modal[data-biometric-type="face"] .biometric-visualizer-step-icon {
                color: #198754;
            }

            .modal[data-biometric-type="face"] .biometric-visualizer-step-icon {
                background: rgba(25, 135, 84, 0.12);
                box-shadow: inset 0 0 0 1px rgba(25, 135, 84, 0.08);
            }

            .modal[data-biometric-type="face"] .biometric-visualizer-scanline {
                background: linear-gradient(90deg, rgba(32, 201, 151, 0), rgba(32, 201, 151, 0.95), rgba(32, 201, 151, 0));
                box-shadow: 0 0 18px rgba(32, 201, 151, 0.45);
            }

            .modal[data-biometric-type="face"] .biometric-visualizer-progress {
                background: linear-gradient(90deg, #198754 0%, #0dcaf0 100%);
            }

            @keyframes biometricVisualizerPulse {
                0%, 100% {
                    transform: scale(0.92);
                    opacity: 0.78;
                }
                50% {
                    transform: scale(1.08);
                    opacity: 1;
                }
            }

            @keyframes biometricVisualizerScan {
                0% {
                    transform: translateY(-72px);
                    opacity: 0;
                }
                15%,
                85% {
                    opacity: 1;
                }
                100% {
                    transform: translateY(72px);
                    opacity: 0;
                }
            }
        </style>
    @endpush

    @push('extra_js')
        <script>
            if (!window.initBiometricEnrollmentVisualizer) {
                window.initBiometricEnrollmentVisualizer = function initBiometricEnrollmentVisualizer(options) {
                    const form = document.querySelector(options.formSelector);
                    const modalElement = document.getElementById(options.modalId || 'biometricEnrollmentVisualizerModal');

                    if (!form || !modalElement || !window.bootstrap || !window.fetch) {
                        return;
                    }

                    const modal = bootstrap.Modal.getOrCreateInstance(modalElement, {
                        backdrop: 'static',
                    });
                    const typeSelector = options.typeSelector ? document.querySelector(options.typeSelector) : null;
                    const submitButton = form.querySelector(options.submitButtonSelector || '[type="submit"]');
                    const phaseLabel = modalElement.querySelector('[data-visualizer-phase-label]');
                    const title = modalElement.querySelector('[data-visualizer-title]');
                    const icon = modalElement.querySelector('[data-visualizer-icon]');
                    const message = modalElement.querySelector('[data-visualizer-message]');
                    const meta = modalElement.querySelector('[data-visualizer-meta]');
                    const progressBar = modalElement.querySelector('[data-visualizer-progress]');
                    const stepsContainer = modalElement.querySelector('[data-visualizer-steps]');
                    const errorPanel = modalElement.querySelector('[data-visualizer-error]');
                    const footer = modalElement.querySelector('[data-visualizer-footer]');
                    let pollTimer = null;
                    let reloadTimer = null;
                    let requestInFlight = false;

                    function currentType() {
                        return (typeSelector ? typeSelector.value : 'fingerprint') === 'face' ? 'face' : 'fingerprint';
                    }

                    function setBiometricTheme(type) {
                        modalElement.dataset.biometricType = type;

                        if (icon) {
                            icon.className = type === 'face' ? 'bi bi-person-bounding-box' : 'bi bi-fingerprint';
                        }

                        if (title) {
                            title.textContent = type === 'face' ? 'Face Enrollment' : 'Fingerprint Enrollment';
                        }
                    }

                    function stopTimers() {
                        if (pollTimer) {
                            clearTimeout(pollTimer);
                            pollTimer = null;
                        }

                        if (reloadTimer) {
                            clearTimeout(reloadTimer);
                            reloadTimer = null;
                        }
                    }

                    function setProgress(value) {
                        if (!progressBar) {
                            return;
                        }

                        const safeValue = Math.max(0, Math.min(100, Number(value) || 0));
                        progressBar.style.width = safeValue + '%';
                        progressBar.setAttribute('aria-valuenow', String(safeValue));
                    }

                    function setFooterText(text) {
                        if (footer) {
                            footer.textContent = text;
                        }
                    }

                    function renderSteps(steps) {
                        if (!stepsContainer) {
                            return;
                        }

                        function escapeHtml(value) {
                            return String(value || '')
                                .replaceAll('&', '&amp;')
                                .replaceAll('<', '&lt;')
                                .replaceAll('>', '&gt;')
                                .replaceAll('"', '&quot;')
                                .replaceAll("'", '&#039;');
                        }

                        stepsContainer.innerHTML = (Array.isArray(steps) ? steps : []).map(function (step) {
                            const state = step.state || 'pending';
                            const iconName = state === 'completed'
                                ? 'check-lg'
                                : (state === 'error' ? 'x-lg' : (state === 'active' ? 'radioactive' : 'dot'));

                            return `
                                <div class="biometric-visualizer-step is-${state}">
                                    <div class="biometric-visualizer-step-icon">
                                        <i class="bi bi-${iconName}"></i>
                                    </div>
                                    <div>
                                        <div class="biometric-visualizer-step-title">${escapeHtml(step.label)}</div>
                                        <div class="biometric-visualizer-step-description">${escapeHtml(step.description)}</div>
                                    </div>
                                </div>
                            `;
                        }).join('');
                    }

                    function renderError(text) {
                        stopTimers();
                        requestInFlight = false;

                        if (errorPanel) {
                            errorPanel.textContent = text;
                            errorPanel.classList.remove('d-none');
                        }

                        if (phaseLabel) {
                            phaseLabel.textContent = 'Action Required';
                            phaseLabel.className = 'small text-uppercase fw-semibold text-danger';
                        }

                        if (message) {
                            message.textContent = text;
                        }

                        setFooterText('Fix the issue and start the enrollment again.');

                        if (submitButton) {
                            submitButton.disabled = false;
                        }
                    }

                    function clearError() {
                        if (errorPanel) {
                            errorPanel.classList.add('d-none');
                            errorPanel.textContent = '';
                        }
                    }

                    function phaseLabelConfig(phase) {
                        switch (phase) {
                            case 'completed':
                                return { text: 'Completed', className: 'small text-uppercase fw-semibold text-success' };
                            case 'failed':
                                return { text: 'Failed', className: 'small text-uppercase fw-semibold text-danger' };
                            case 'uploading':
                                return { text: 'Uploading Template', className: 'small text-uppercase fw-semibold text-info' };
                            case 'capturing':
                                return { text: 'Capture In Progress', className: 'small text-uppercase fw-semibold text-primary' };
                            default:
                                return { text: 'Waiting For Device', className: 'small text-uppercase fw-semibold text-primary' };
                        }
                    }

                    function renderStatus(status) {
                        const biometricType = status.biometric_type || currentType();
                        const phase = status.phase || 'queued';
                        const labelConfig = phaseLabelConfig(phase);
                        const employeeName = status.employee && status.employee.name ? status.employee.name : 'Selected employee';
                        const deviceName = status.device && status.device.name ? status.device.name : 'selected device';
                        const slot = status.template_index !== null && status.template_index !== undefined && status.template_index !== ''
                            ? 'Slot ' + status.template_index
                            : null;

                        setBiometricTheme(biometricType);
                        clearError();

                        if (phaseLabel) {
                            phaseLabel.textContent = labelConfig.text;
                            phaseLabel.className = labelConfig.className;
                        }

                        if (message) {
                            message.textContent = status.message || 'Enrollment is waiting for the device.';
                        }

                        if (meta) {
                            meta.textContent = [employeeName, deviceName, slot].filter(Boolean).join(' • ');
                        }

                        setProgress(status.progress || 0);
                        renderSteps(status.steps || []);

                        if (phase === 'completed') {
                            setFooterText('The template is stored in ADMS. This page will refresh shortly.');
                        } else if (phase === 'failed') {
                            setFooterText('The terminal did not finish the enrollment. Review the error and retry.');
                        } else {
                            setFooterText('Keep the employee at the terminal until the capture is accepted and uploaded back to ADMS.');
                        }
                    }

                    function extractErrorMessage(payload) {
                        if (!payload) {
                            return 'Unable to start biometric enrollment.';
                        }

                        if (payload.message) {
                            return payload.message;
                        }

                        if (payload.errors && typeof payload.errors === 'object') {
                            return Object.values(payload.errors)
                                .flat()
                                .filter(Boolean)
                                .join(' ');
                        }

                        return 'Unable to start biometric enrollment.';
                    }

                    async function pollStatus(statusUrl, attempts) {
                        const nextAttempt = attempts + 1;

                        try {
                            const response = await fetch(statusUrl, {
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });
                            const payload = await response.json();

                            if (!response.ok) {
                                throw new Error(extractErrorMessage(payload));
                            }

                            renderStatus(payload);

                            if (payload.phase === 'completed') {
                                requestInFlight = false;

                                if (submitButton) {
                                    submitButton.disabled = false;
                                }

                                reloadTimer = setTimeout(function () {
                                    window.location.reload();
                                }, 1800);

                                return;
                            }

                            if (payload.phase === 'failed') {
                                requestInFlight = false;

                                if (submitButton) {
                                    submitButton.disabled = false;
                                }

                                return;
                            }

                            if (nextAttempt >= 60) {
                                renderError('The device has not completed enrollment yet. Keep the modal open a little longer or check the terminal screen.');
                                return;
                            }

                            pollTimer = setTimeout(function () {
                                pollStatus(statusUrl, nextAttempt);
                            }, 2500);
                        } catch (error) {
                            renderError(error.message || 'Unable to read enrollment progress from the server.');
                        }
                    }

                    function renderPendingState() {
                        const biometricType = currentType();

                        setBiometricTheme(biometricType);
                        clearError();
                        modal.show();

                        if (phaseLabel) {
                            phaseLabel.textContent = 'Queueing Command';
                            phaseLabel.className = 'small text-uppercase fw-semibold text-primary';
                        }

                        if (message) {
                            message.textContent = biometricType === 'face'
                                ? 'Preparing the face enrollment prompt for the selected terminal...'
                                : 'Preparing the fingerprint enrollment prompt for the selected terminal...';
                        }

                        if (meta) {
                            meta.textContent = 'ADMS will deliver the enrollment command on the next device poll.';
                        }

                        setProgress(8);
                        renderSteps([
                            {
                                label: 'Command Queued',
                                description: 'The server is saving the remote enrollment request.',
                                state: 'active',
                            },
                            {
                                label: biometricType === 'face' ? 'Capture Face' : 'Capture Finger',
                                description: biometricType === 'face'
                                    ? 'The employee will be asked to look at the device camera.'
                                    : 'The employee will be asked to place the same finger on the scanner several times.',
                                state: 'pending',
                            },
                            {
                                label: 'Upload To ADMS',
                                description: 'The captured template will be uploaded back to this server.',
                                state: 'pending',
                            },
                        ]);
                        setFooterText('Keep the employee ready at the terminal while ADMS dispatches the command.');
                    }

                    form.addEventListener('submit', async function (event) {
                        if (requestInFlight) {
                            event.preventDefault();
                            return;
                        }

                        event.preventDefault();
                        requestInFlight = true;
                        stopTimers();
                        renderPendingState();

                        if (submitButton) {
                            submitButton.disabled = true;
                        }

                        try {
                            const response = await fetch(form.action, {
                                method: (form.method || 'POST').toUpperCase(),
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': form.querySelector('input[name="_token"]') ? form.querySelector('input[name="_token"]').value : '',
                                },
                                body: new FormData(form),
                            });
                            const payload = await response.json();

                            if (!response.ok) {
                                throw new Error(extractErrorMessage(payload));
                            }

                            if (payload.status) {
                                renderStatus(payload.status);
                            }

                            if (payload.status_url) {
                                pollStatus(payload.status_url, 0);
                                return;
                            }

                            throw new Error('The server did not return a biometric status URL.');
                        } catch (error) {
                            renderError(error.message || 'Unable to start biometric enrollment.');
                        }
                    });

                    modalElement.addEventListener('hidden.bs.modal', function () {
                        stopTimers();
                        requestInFlight = false;

                        if (submitButton) {
                            submitButton.disabled = false;
                        }
                    });
                };
            }
        </script>
    @endpush
@endonce
