(function () {
    let MeerkatReply = {
        closeOnCancel: true,
        replyOpen: null,
        canceled: null,
        submit: function () {},
        getOpenReplyForm: function () {
            let forms = document.querySelectorAll('form[data-meerkat-form="comment-reply-form"]');

            return forms[forms.length - 1];
        }
    };

    const HCAPTCHA_CLASS = /(^|\s)h-captcha(\s|$)/;
    const RECAPTCHA_CLASS = /(^|\s)g-recaptcha(\s|$)/;

    const MeerkatForms = {
        data: {
            ReplyForm: null,
            Extend: null
        },
        findClosest: function (el, selector) {
            let matchesFn;

            [
                'matches', 'webkitMatchesSelector', 'mozMatchesSelector',
                'msMatchesSelector', 'oMatchesSelector']
                .some(function (fn) {
                    if (typeof document.body[fn] === 'function') {
                        matchesFn = fn;
                        return true;
                    }
                    return false;
                });

            let parent;

            while (el) {
                parent = el.parentElement;
                if (parent && parent[matchesFn](selector)) {
                    return parent;
                }
                el = parent;
            }

            return null;
        },
        generateId: function () {
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }

            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                let r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);

                return v.toString(16);
            });
        },
        findElementWithClass: function (node, classRegex) {
            let matches = [];

            function traverse(childNode) {
                for (let i = 0; i < childNode.childNodes.length; i++) {
                    if (childNode.childNodes[i].getAttribute && childNode.childNodes[i].getAttribute('class')) {
                        if (childNode.childNodes[i].getAttribute('class').match(classRegex)) {
                            matches.push(childNode.childNodes[i]);
                        }
                    }

                    if (childNode.childNodes[i].childNodes.length > 0) {
                        traverse(childNode.childNodes[i]);
                    }
                }
            }

            traverse(node);

            return matches;
        },
        getReplyForm: function () {
            let state = {
                form: null,
                IsHCaptchaInUse: false,
                IsGoogleRecaptchaInUse: false,
                CaptchaElementId: null,
                GoogleRecaptchaInstance: null,
                HCaptchaInstance: null,
                GoogleRecaptchaTheme: null,
                GoogleRecaptchaSiteKey: null,
                HCaptchaSiteKey: null
            };

            let form = document.querySelectorAll('[data-meerkat-form="comment-reply-form"]');

            if (form.length === 0) {
                form = document.querySelectorAll('[data-meerkat-form="comment-form"]');
            }

            if (form.length > 0) {
                let meerkatReplyForm = form[0].cloneNode(true);

                this.prepareClonedForm(meerkatReplyForm);

                if (meerkatReplyForm.innerHTML.indexOf('h-captcha') > -1) {
                    state.IsHCaptchaInUse = true;
                    state.IsGoogleRecaptchaInUse = false;

                    let captchaElements = this.findElementWithClass(meerkatReplyForm, HCAPTCHA_CLASS);

                    if (typeof captchaElements !== 'undefined' && captchaElements.length > 0) {
                        let captchaEle = captchaElements[0];

                        state.CaptchaElementId = 'meerkat_c-' + this.generateId();
                        captchaEle.setAttribute('id', state.CaptchaElementId);

                        if (typeof  captchaEle.dataset !== 'undefined') {
                            let captchaDataSet = captchaEle.dataset;

                            state.HCaptchaSiteKey = captchaDataSet.sitekey;
                        }
                    }
                } else if (meerkatReplyForm.innerHTML.indexOf('g-recaptcha') > -1) {
                    if (typeof window['grecaptcha'] !== 'undefined') {
                        state.IsGoogleRecaptchaInUse = true;
                        state.IsHCaptchaInUse = false;

                        let captchaElements = this.findElementWithClass(meerkatReplyForm, RECAPTCHA_CLASS);

                        if (typeof captchaElements !== 'undefined' && captchaElements.length > 0) {
                            let captchaEle = captchaElements[0];

                            state.CaptchaElementId = 'meerkat_c-' + this.generateId();
                            captchaEle.setAttribute('id', state.CaptchaElementId);

                            if (typeof captchaEle.dataset !== 'undefined') {
                                let captchaDataSet = captchaEle.dataset;

                                if (typeof captchaDataSet.sitekey !== 'undefined') {
                                    state.GoogleRecaptchaSiteKey = captchaDataSet.sitekey;
                                }

                                if (typeof captchaDataSet.theme !== 'undefined') {
                                    state.GoogleRecaptchaTheme = captchaDataSet.theme;
                                } else {
                                    state.GoogleRecaptchaTheme = 'light';
                                }
                            }
                        }
                    }
                }

                state.form = meerkatReplyForm;
            } else {
                state.form = form;
            }

            return state;
        },
        makeReplyInput: function (replyingTo) {
            let replyInput = document.createElement('input');

            replyInput.type = 'hidden';
            replyInput.value = replyingTo;
            replyInput.name = 'ids';

            return replyInput;
        },
        prepareClonedForm: function (form) {
            form.querySelectorAll('input, textarea, select').forEach(function (control) {
                control.removeAttribute('id');

                let type = (control.getAttribute('type') || '').toLowerCase();

                if (control.tagName === 'TEXTAREA' || ['text', 'email', 'url', 'search', 'tel'].indexOf(type) > -1) {
                    control.value = '';
                }
            });

            form.querySelectorAll('label[for]').forEach(function (label) {
                label.removeAttribute('for');
            });
        },
        guardAgainstDoubleSubmit: function (form) {
            let submitting = false;

            form.addEventListener('submit', function (event) {
                if (submitting) {
                    event.preventDefault();

                    return;
                }

                submitting = true;
            });
        },
        renderCaptcha: function (state) {
            if (state.IsGoogleRecaptchaInUse && state.CaptchaElementId !== null) {
                if (state.GoogleRecaptchaTheme !== null && state.GoogleRecaptchaSiteKey !== null) {
                    window.setTimeout(function () {
                        let captchaElement = window.document.getElementById(state.CaptchaElementId);

                        if (! captchaElement) {
                            return;
                        }

                        captchaElement.innerHTML = '';

                        try {
                            state.GoogleRecaptchaInstance = window.grecaptcha.render(state.CaptchaElementId, {
                                'sitekey': state.GoogleRecaptchaSiteKey,
                                'theme': state.GoogleRecaptchaTheme
                            });
                        } catch (err) {
                            console.warn('Meerkat: captcha render failed', err);
                        }
                    }, 250);
                }
            }

            if (state.IsHCaptchaInUse === true && state.CaptchaElementId !== null) {
                if (state.HCaptchaSiteKey !== null) {
                    window.setTimeout(function () {
                        let captchaElement = window.document.getElementById(state.CaptchaElementId);

                        if (! captchaElement) {
                            return;
                        }

                        captchaElement.innerHTML = '';

                        try {
                            state.HCaptchaInstance = window.hcaptcha.render(state.CaptchaElementId, {
                                'sitekey': state.HCaptchaSiteKey
                            });
                        } catch (err) {
                            console.warn('Meerkat: captcha render failed', err);
                        }
                    }, 250);
                }
            }
        },

        // Scope cancellation to this form to avoid duplicate handlers.
        bindCancelListener: function (replyForm) {
            let cancelLinks = replyForm.querySelectorAll('[data-meerkat-form="cancel-reply"]');

            cancelLinks.forEach(function (el) {
                el.addEventListener('click', function (event) {
                    let meerkatForm = MeerkatForms.findClosest(el, 'form[data-meerkat-form]');

                    if (typeof meerkatForm !== 'undefined' && meerkatForm !== null) {
                        let idsInput = meerkatForm.querySelectorAll('[name=ids]')[0];

                        if (typeof idsInput === 'undefined' || idsInput === null) {
                            event.preventDefault();
                            return;
                        }

                        let replyingTo = idsInput.value;

                        if (typeof MeerkatForms.data.Extend.canceled !== 'undefined' && MeerkatForms.data.Extend.canceled !== null) {
                            MeerkatForms.data.Extend.canceled(replyingTo, meerkatForm);
                        }

                        if (MeerkatForms.data.Extend.closeOnCancel) {
                            meerkatForm.remove();
                        }
                    }

                    event.preventDefault();
                });
            });
        },
        addEventListeners: function () {
            let _this = this,
                replyLinks = document.querySelectorAll('[data-meerkat-form="reply"]');

            replyLinks.forEach(function (el) {
                el.addEventListener('click', function (event) {

                    if (_this.data.ReplyForm !== null && _this.data.ReplyForm.parentNode != null) {
                        _this.data.ReplyForm.parentNode.removeChild(_this.data.ReplyForm);
                    }

                    let state = _this.getReplyForm();
                    let replyForm = state.form;

                    _this.data.ReplyForm = replyForm;

                    let replyingTo = el.getAttribute('data-meerkat-reply-to');

                    replyForm.appendChild(_this.makeReplyInput(replyingTo));
                    _this.guardAgainstDoubleSubmit(replyForm);
                    replyForm.addEventListener('submit', _this.data.Extend.submit, false);

                    if (typeof _this.data.Extend.replyOpen !== 'undefined' &&
                        _this.data.Extend.replyOpen !== null) {
                        _this.data.Extend.replyOpen(replyForm);
                    }

                    el.parentNode.insertBefore(replyForm, el.nextSibling);

                    _this.renderCaptcha(state);

                    _this.bindCancelListener(replyForm);
                    event.preventDefault();
                });
            });
        },
        init: function () {
            this.data.Extend = MeerkatReply;
            this.getReplyForm();
            this.addEventListeners();

            let _this = this;
            document.querySelectorAll('[data-meerkat-form="comment-form"]').forEach(function (form) {
                _this.guardAgainstDoubleSubmit(form);
            });

            window.MeerkatReply = this.data.Extend;
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        MeerkatForms.init();
    });
})();
