document.addEventListener('DOMContentLoaded', () => {
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const reportCategory = document.querySelector('[data-report-category]');
    const categoryButtons = document.querySelectorAll('[data-category-select]');

    const syncSelectedCategory = () => {
        if (!reportCategory) return;
        categoryButtons.forEach((button) => {
            const selected = button.dataset.categorySelect === reportCategory.value;
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            button.classList.toggle('is-selected', selected);
        });
    };

    categoryButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (!reportCategory) return;
            reportCategory.value = button.dataset.categorySelect;
            syncSelectedCategory();
            document.getElementById('report-form')?.scrollIntoView({
                behavior: reducedMotion ? 'auto' : 'smooth',
                block: 'start',
            });
            reportCategory.focus({ preventScroll: true });
        });
    });
    reportCategory?.addEventListener('change', syncSelectedCategory);
    syncSelectedCategory();

    const reportForm = document.querySelector('[data-community-report-form]');
    if (reportForm) {
        const attachmentInput = reportForm.querySelector('[data-report-attachments]');
        const reportTitle = reportForm.querySelector('[data-report-title]');
        const reportDescription = reportForm.querySelector('[data-report-description]');
        const previewList = reportForm.querySelector('[data-media-preview]');
        const gpsButton = reportForm.querySelector('[data-use-location]');
        const gpsStatus = reportForm.querySelector('[data-gps-status]');
        const latitudeInput = reportForm.querySelector('[data-report-latitude]');
        const longitudeInput = reportForm.querySelector('[data-report-longitude]');
        const locationInput = reportForm.querySelector('[data-report-location]');
        const mapPreview = reportForm.querySelector('[data-report-map]');
        const mapFrame = mapPreview?.querySelector('iframe');
        const mapLink = mapPreview?.querySelector('[data-map-link]');
        let previewUrls = [];

        const renderSelectedMedia = () => {
            if (!attachmentInput) return;
            const files = Array.from(attachmentInput.files || []);
            const hasAttachments = files.length > 0;
            if (reportTitle) reportTitle.required = !hasAttachments;
            if (reportDescription) reportDescription.required = !hasAttachments;
            let fileError = files.length > 4 ? 'Attach no more than 4 files.' : '';
            const fileLimits = {
                'image/jpeg': 10,
                'image/png': 10,
                'image/gif': 10,
                'image/webp': 10,
                'video/mp4': 25,
                'video/webm': 25,
                'video/quicktime': 25,
                'audio/mpeg': 15,
                'audio/mp4': 15,
                'audio/aac': 15,
                'audio/ogg': 15,
                'audio/webm': 15,
                'audio/wav': 15,
                'audio/x-wav': 15,
            };
            let totalSize = 0;
            for (const file of files) {
                totalSize += file.size;
                const limit = fileLimits[file.type];
                if (!fileError && (!limit || file.size < 1 || file.size > limit * 1024 * 1024)) {
                    fileError = 'Choose supported images up to 10 MB, audio up to 15 MB, or videos up to 25 MB each.';
                }
            }
            if (!fileError && totalSize > 40 * 1024 * 1024) {
                fileError = 'The total size of report attachments must be 40 MB or less.';
            }
            attachmentInput.setCustomValidity(fileError);

            previewUrls.forEach((url) => URL.revokeObjectURL(url));
            previewUrls = [];
            if (!previewList) return;
            previewList.replaceChildren();
            files.forEach((file) => {
                const item = document.createElement('div');
                item.className = 'media-preview-item';
                const mediaType = file.type.split('/')[0];
                if (mediaType === 'image' || mediaType === 'video') {
                    const preview = document.createElement(mediaType === 'image' ? 'img' : 'video');
                    const url = URL.createObjectURL(file);
                    previewUrls.push(url);
                    preview.src = url;
                    preview.setAttribute('aria-hidden', 'true');
                    if (mediaType === 'video') {
                        preview.muted = true;
                        preview.playsInline = true;
                    }
                    item.append(preview);
                }
                const label = document.createElement('span');
                label.textContent = `${file.name} · ${(file.size / (1024 * 1024)).toFixed(1)} MB`;
                item.append(label);
                previewList.append(item);
            });
        };

        attachmentInput?.addEventListener('change', renderSelectedMedia);
        renderSelectedMedia();

        const renderLocationMap = (latitude, longitude) => {
            if (!mapPreview || !mapFrame || !mapLink) return;
            const marker = `${latitude},${longitude}`;
            const bounds = [
                Math.max(-180, longitude - 0.006),
                Math.max(-90, latitude - 0.004),
                Math.min(180, longitude + 0.006),
                Math.min(90, latitude + 0.004),
            ].join(',');
            mapFrame.src = `https://www.openstreetmap.org/export/embed.html?bbox=${bounds}&layer=mapnik&marker=${marker}`;
            mapLink.href = `https://www.openstreetmap.org/?mlat=${latitude}&mlon=${longitude}#map=17/${latitude}/${longitude}`;
            mapPreview.hidden = false;
        };

        if (latitudeInput?.value && longitudeInput?.value) {
            renderLocationMap(Number(latitudeInput.value), Number(longitudeInput.value));
        }
        gpsButton?.addEventListener('click', () => {
            if (!navigator.geolocation) {
                if (gpsStatus) gpsStatus.textContent = 'This browser does not provide GPS location. Enter a street or landmark instead.';
                return;
            }
            gpsButton.disabled = true;
            if (gpsStatus) gpsStatus.textContent = 'Finding your location…';
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const latitude = position.coords.latitude;
                    const longitude = position.coords.longitude;
                    if (latitudeInput) latitudeInput.value = latitude.toFixed(7);
                    if (longitudeInput) longitudeInput.value = longitude.toFixed(7);
                    if (locationInput && (!locationInput.value.trim() || locationInput.value.startsWith('GPS coordinates '))) {
                        locationInput.value = `GPS coordinates ${latitude.toFixed(5)}, ${longitude.toFixed(5)}`;
                    }
                    renderLocationMap(latitude, longitude);
                    if (gpsStatus) gpsStatus.textContent = 'GPS location added. You can edit the location label before submitting.';
                    gpsButton.disabled = false;
                },
                (error) => {
                    const messages = {
                        1: 'Location permission was denied. Allow access or enter a street or landmark.',
                        2: 'Your location could not be determined. Try again or enter a street or landmark.',
                        3: 'Finding your location took too long. Try again or enter a street or landmark.',
                    };
                    if (gpsStatus) gpsStatus.textContent = messages[error.code] || 'GPS location failed. Enter a street or landmark instead.';
                    gpsButton.disabled = false;
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
            );
        });

        const recordButton = reportForm.querySelector('[data-record-audio]');
        const recordingStatus = reportForm.querySelector('[data-recording-status]');
        if (recordButton && (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder)) {
            recordButton.hidden = true;
            if (recordingStatus) recordingStatus.textContent = 'Voice recording is not available in this browser. You can still attach an audio file.';
        }
        recordButton?.addEventListener('click', async () => {
            if (!attachmentInput || !recordingStatus) return;
            if (recordButton.dataset.recording === 'true') {
                recordButton.disabled = true;
                recordButton.textContent = 'Saving recording…';
                recordButton.recorder?.stop();
                return;
            }
            if (attachmentInput.files.length >= 4) {
                recordingStatus.textContent = 'Remove an attachment before recording a voice report.';
                return;
            }
            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                const recorder = new MediaRecorder(stream);
                const chunks = [];
                recorder.addEventListener('dataavailable', (event) => {
                    if (event.data.size > 0) chunks.push(event.data);
                });
                recorder.addEventListener('stop', () => {
                    stream.getTracks().forEach((track) => track.stop());
                    const mimeType = recorder.mimeType || chunks[0]?.type || 'audio/webm';
                    const extension = mimeType.includes('ogg') ? 'ogg' : mimeType.includes('mp4') ? 'm4a' : 'webm';
                    const recording = new File(
                        [new Blob(chunks, { type: mimeType })],
                        `voice-report-${Date.now()}.${extension}`,
                        { type: mimeType },
                    );
                    const transfer = new DataTransfer();
                    Array.from(attachmentInput.files).forEach((file) => transfer.items.add(file));
                    transfer.items.add(recording);
                    attachmentInput.files = transfer.files;
                    renderSelectedMedia();
                    recordingStatus.textContent = 'Voice recording added to your report.';
                    recordButton.textContent = 'Record another voice report';
                    recordButton.dataset.recording = 'false';
                    recordButton.disabled = false;
                });
                recorder.addEventListener('error', () => {
                    stream.getTracks().forEach((track) => track.stop());
                    recordingStatus.textContent = 'The voice recording failed. Please try again or attach an audio file.';
                    recordButton.textContent = 'Record a voice report';
                    recordButton.dataset.recording = 'false';
                    recordButton.disabled = false;
                });
                recordButton.recorder = recorder;
                recorder.start();
                recordButton.dataset.recording = 'true';
                recordButton.textContent = 'Stop recording';
                recordingStatus.textContent = 'Recording… select Stop recording when you are finished.';
            } catch (error) {
                stream?.getTracks().forEach((track) => track.stop());
                recordingStatus.textContent = error.name === 'NotAllowedError'
                    ? 'Microphone permission was denied. Allow access or attach an audio file.'
                    : 'The microphone could not be started. Check your browser permissions and try again.';
            }
        });

        const backgroundVideo = document.querySelector('[data-background-video]');
        const videoToggle = document.querySelector('[data-video-toggle]');
        if (backgroundVideo && videoToggle) {
            if (reducedMotion) {
                backgroundVideo.pause();
                videoToggle.textContent = 'Play video';
                videoToggle.setAttribute('aria-label', 'Play community background video');
            }
            videoToggle.addEventListener('click', () => {
                if (backgroundVideo.paused) {
                    backgroundVideo.play().then(() => {
                        videoToggle.textContent = 'Pause video';
                        videoToggle.setAttribute('aria-label', 'Pause community background video');
                    }).catch(() => {
                        videoToggle.textContent = 'Video unavailable';
                        videoToggle.setAttribute('aria-label', 'Community background video could not be played');
                    });
                    return;
                }
                backgroundVideo.pause();
                videoToggle.textContent = 'Play video';
                videoToggle.setAttribute('aria-label', 'Play community background video');
            });
        }
    }

    const revealItems = document.querySelectorAll('[data-reveal]');
    if (revealItems.length && !reducedMotion && 'IntersectionObserver' in window) {
        document.body.classList.add('motion-ready');
        const revealObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -36px 0px' });

        revealItems.forEach((item, index) => {
            item.classList.add('reveal');
            item.style.setProperty('--reveal-delay', `${Math.min(index % 5, 4) * 65}ms`);
            revealObserver.observe(item);
        });
    }

    document.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                event.preventDefault();
                return;
            }
            const button = form.querySelector('button[type="submit"]');
            if (button && form.checkValidity()) {
                button.dataset.originalText = button.textContent;
                button.textContent = button.dataset.loadingText || 'Please wait…';
                button.classList.add('is-loading');
                button.disabled = true;
            }
        });
    });

    document.querySelectorAll('form[data-otp-autosubmit] input[autocomplete="one-time-code"]').forEach((input) => {
        const submitWhenComplete = () => {
            const form = input.form;
            if (
                form &&
                form.dataset.otpSubmitting !== 'true' &&
                /^[0-9]{6}$/.test(input.value) &&
                form.reportValidity()
            ) {
                form.dataset.otpSubmitting = 'true';
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.dataset.loadingText = 'Verifying code…';
                    button.disabled = true;
                    button.classList.add('is-loading');
                }
                input.readOnly = true;

                let secondsRemaining = 2;
                const updateCountdown = () => {
                    if (secondsRemaining > 0) {
                        if (button) {
                            button.textContent = `Verifying in ${secondsRemaining}…`;
                        }
                        secondsRemaining -= 1;
                        window.setTimeout(updateCountdown, 1000);
                        return;
                    }

                    if (button) {
                        button.disabled = false;
                        button.textContent = button.dataset.loadingText;
                    }
                    form.requestSubmit();
                };
                updateCountdown();
            }
        };
        input.addEventListener('input', submitWhenComplete);
        input.addEventListener('change', submitWhenComplete);
        submitWhenComplete();
    });

    document.querySelectorAll('form[data-auto-email-verify]').forEach((form) => {
        const submitButton = form.querySelector('button[type="submit"]');
        if (!submitButton) return;

        submitButton.textContent = 'Verifying…';
        form.requestSubmit(submitButton);
    });

    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.dataset.copyTarget);
            if (!target) return;

            try {
                await navigator.clipboard.writeText(target.textContent.trim());
                const oldText = button.textContent;
                button.textContent = 'Copied';
                window.setTimeout(() => { button.textContent = oldText; }, 1400);
            } catch {
                target.focus();
                window.getSelection()?.selectAllChildren(target);
            }
        });
    });

    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.togglePassword);
            if (!input) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            button.textContent = showing ? 'Show' : 'Hide';
        });
    });

    const password = document.querySelector('[data-password-meter]');
    const meter = document.getElementById('password-meter');
    const strengthLabel = document.getElementById('password-strength-label');
    if (password && meter && strengthLabel) {
        password.addEventListener('input', () => {
            const value = password.value;
            let score = 0;
            if (value.length >= 12) score++;
            if (value.length >= 16) score++;
            if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
            if (/\d/.test(value)) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;

            const strength = value.length === 0 ? '' : score <= 1 ? 'weak' : score <= 2 ? 'fair' : score <= 3 ? 'good' : 'strong';
            meter.dataset.strength = strength;
            strengthLabel.dataset.strength = strength;
            strengthLabel.textContent = strength ? `${strength[0].toUpperCase()}${strength.slice(1)} password` : 'Choose a unique passphrase of at least 12 characters.';
        });
    }
});
