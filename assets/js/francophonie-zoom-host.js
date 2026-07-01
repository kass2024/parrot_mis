/**
 * Francophonie host room — Zoom Client View (matches Parrot-Learning zoomClientSession).
 */
(function (global) {
  function zoomErr(err) {
    if (!err || typeof err !== 'object') return 'Zoom meeting error';
    return err.reason || err.message || 'Zoom meeting error';
  }

  function passwordCandidates(sdk) {
    const fromApi = Array.isArray(sdk.password_candidates) ? sdk.password_candidates : [];
    const ordered = [sdk.password || '', ...fromApi].map(function (v) {
      return String(v || '').trim();
    });
    const unique = [];
    ordered.forEach(function (p) {
      if (unique.indexOf(p) === -1) unique.push(p);
    });
    if (unique.length === 0) unique.push('');
    return unique;
  }

  function prepareSdk(zoomLibUrl) {
    return new Promise(function (resolve, reject) {
      try {
        if (typeof ZoomMtg.setZoomJSLib === 'function') {
          ZoomMtg.setZoomJSLib(zoomLibUrl, '/av');
        }
        ZoomMtg.preLoadWasm();
        var prep = ZoomMtg.prepareWebSDK();
        if (prep && typeof prep.then === 'function') {
          prep.then(function () { resolve(); }).catch(function (e) { reject(e); });
        } else {
          setTimeout(resolve, 600);
        }
      } catch (e) {
        reject(e);
      }
    });
  }

  function initClient(leaveUrl) {
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () {
        reject(new Error('Timed out initializing Zoom (60s). Check your connection and refresh.'));
      }, 60000);

      ZoomMtg.init({
        leaveUrl: leaveUrl,
        patchJsMedia: true,
        leaveOnPageUnload: true,
        showPureSharingContent: true,
        success: function () {
          clearTimeout(timer);
          resolve();
        },
        error: function (err) {
          clearTimeout(timer);
          reject(new Error(zoomErr(err)));
        },
      });
    });
  }

  function joinOnce(sdk, passWord, useZak) {
    return new Promise(function (resolve, reject) {
      var settled = false;
      function finish(fn) {
        if (settled) return;
        settled = true;
        fn();
      }

      var timer = setTimeout(function () {
        finish(function () {
          reject(new Error('Timed out joining the meeting (2 min). Try “Open in Zoom app” from the invitations page.'));
        });
      }, 120000);

      function statusHandler(data) {
        if (data && data.status === 2) {
          clearTimeout(timer);
          finish(resolve);
        } else if (data && data.status === 3) {
          clearTimeout(timer);
          finish(function () { reject(new Error('Disconnected from the Zoom meeting.')); });
        }
      }

      ZoomMtg.inMeetingServiceListener('onMeetingStatus', statusHandler);

      var payload = {
        signature: sdk.signature,
        meetingNumber: String(sdk.meeting_number),
        userName: sdk.user_name || 'Host',
        passWord: passWord,
        success: function () {
          setTimeout(function () {
            clearTimeout(timer);
            finish(resolve);
          }, 2000);
        },
        error: function (err) {
          clearTimeout(timer);
          finish(function () { reject(new Error(zoomErr(err))); });
        },
      };

      if (sdk.user_email) payload.userEmail = sdk.user_email;
      if (useZak && sdk.role === 1 && sdk.zak) payload.zak = sdk.zak;

      ZoomMtg.join(payload);
    });
  }

  function joinWithRetries(sdk) {
    var passwords = passwordCandidates(sdk);
    var zakModes = sdk.zak ? [true, false] : [false];
    var lastError = 'Failed to join the Zoom meeting.';

    function attemptZi(zi) {
      if (zi >= zakModes.length) return Promise.reject(new Error(lastError));
      var useZak = zakModes[zi];

      function attemptPi(pi) {
        if (pi >= passwords.length) return attemptZi(zi + 1);
        return joinOnce(sdk, passwords[pi], useZak).catch(function (err) {
          lastError = err instanceof Error ? err.message : lastError;
          if (!/password|passcode|wrong|zak/i.test(lastError)) {
            return Promise.reject(new Error(lastError));
          }
          return attemptPi(pi + 1);
        });
      }

      return attemptPi(0);
    }

    return attemptZi(0);
  }

  function showZoomRoot() {
    document.documentElement.classList.add('zoom-client-meeting-active');
    document.body.classList.add('zoom-client-meeting-active');
    var root = document.getElementById('zmmtg-root');
    if (root) root.style.display = 'block';
  }

  global.startFrancophonieZoomHost = function (options) {
    var sdk = options.sdk;
    var leaveUrl = options.leaveUrl;
    var zoomLibUrl = options.zoomLibUrl;
    var onStatus = options.onStatus || function () {};
    var onError = options.onError || function () {};
    var onJoined = options.onJoined || function () {};

    if (!sdk || !sdk.signature) {
      onError('SDK credentials were not generated.');
      return;
    }
    if (typeof ZoomMtg === 'undefined') {
      onError('Zoom Meeting SDK failed to load. Run npm install in the parrot_mis folder.');
      return;
    }

    showZoomRoot();
    onStatus('Loading Zoom components…');

    prepareSdk(zoomLibUrl)
      .then(function () {
        onStatus('Initializing meeting…');
        return initClient(leaveUrl);
      })
      .then(function () {
        onStatus('Joining as host…');
        return joinWithRetries(sdk);
      })
      .then(function () {
        onJoined();
      })
      .catch(function (err) {
        onError(err instanceof Error ? err.message : String(err));
      });
  };
})(window);
