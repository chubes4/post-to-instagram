import { useState, useEffect } from '@wordpress/element';

/**
 * Instagram authentication state management with OAuth popup handling.
 *
 * @param {Object} i18n - Internationalization strings
 * @param {string} auth_redirect_status - OAuth redirect status from URL
 * @returns {Object} Authentication state and handlers
 */
export default function useInstagramAuth(i18n, auth_redirect_status) {
  const {
    is_configured: initialIsConfigured,
    is_authenticated: initialIsAuthenticated,
    auth_url: initialAuthUrl,
    app_id: initialAppId,
    username: initialUsername,
    nonce_disconnect,
  } = window.pti_data;

  const [isConfigured, setIsConfigured] = useState(initialIsConfigured);
  const [isAuthenticated, setIsAuthenticated] = useState(initialIsAuthenticated);
  const [authUrl, setAuthUrl] = useState(initialAuthUrl);
  const [savedAppId, setSavedAppId] = useState(initialAppId || '');
  const [username, setUsername] = useState(initialUsername || null);
  const [disconnecting, setDisconnecting] = useState(false);
  const [isLoading, setIsLoading] = useState(true);

  const checkAuthStatus = (showAlerts = false) => {
    setIsLoading(true);
    wp.apiFetch({
      path: 'pti/v1/auth/status',
      method: 'GET',
    })
      .then((data) => {
        setIsConfigured(data.is_configured);
        setIsAuthenticated(data.is_authenticated);
        setAuthUrl(data.auth_url || '#');
        setSavedAppId(data.app_id || '');
        setUsername(data.username || null);
        setIsLoading(false);
      })
      .catch((error) => {
        setIsLoading(false);
        if (showAlerts) alert(i18n.generic_auth_error || 'Error checking authentication status.');
      });
  };

  useEffect(() => {
    const handleAuthMessage = (event) => {
      if (event.data && event.data.type === 'pti_auth_complete') {
        if (event.data.success) {
          checkAuthStatus(true);
          if (window.oauthWindow && !window.oauthWindow.closed) {
            window.oauthWindow.close();
          }
        } else {
          alert(i18n.auth_failed + (event.data.message ? '\n' + event.data.message : ''));
        }
      }
    };
    window.addEventListener('message', handleAuthMessage);
    return () => window.removeEventListener('message', handleAuthMessage);
  }, [i18n]);

  useEffect(() => {
    if (auth_redirect_status) {
      if (auth_redirect_status === 'success') {
        checkAuthStatus(true);
      } else {
        let errorMessage = i18n.auth_failed;
        if (i18n[auth_redirect_status]) {
          errorMessage = i18n[auth_redirect_status];
        } else if (auth_redirect_status !== '1') {
          errorMessage += ` (Details: ${auth_redirect_status})`;
        }
        alert(errorMessage);
      }
      if (window.history.replaceState) {
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.delete('pti_auth_success');
        currentUrl.searchParams.delete('pti_auth_error');
        window.history.replaceState({ path: currentUrl.href }, '', currentUrl.href);
      }
    }
  }, [auth_redirect_status, i18n]);

  useEffect(() => {
    checkAuthStatus();
  }, []);

  const handleConnectInstagram = () => {
    if (!isConfigured) {
      alert(i18n.not_configured_for_auth || 'App not configured.');
      return;
    }
    if (authUrl && authUrl !== '#') {
      window.oauthWindow = window.open(authUrl, 'ptiOAuthConnect', 'width=600,height=700');
      const timer = setInterval(() => {
        if (window.oauthWindow && window.oauthWindow.closed) {
          clearInterval(timer);
          checkAuthStatus(true);
        }
      }, 1000);
    } else {
      alert(i18n.generic_auth_error || 'Authentication URL not available.');
    }
  };

  const handleDisconnect = async () => {
    if (!window.confirm(i18n.disconnect_instagram || 'Disconnect Instagram Account?')) return;
    setDisconnecting(true);
    try {
      const response = await wp.apiFetch({
        path: '/pti/v1/disconnect',
        method: 'POST',
        data: { _wpnonce: nonce_disconnect },
      });
      setDisconnecting(false);
      if (response && response.success) {
        wp.data.dispatch('core/notices').createNotice('success', i18n.disconnected || 'Account disconnected.', { isDismissible: true });
        checkAuthStatus();
      } else {
        wp.data.dispatch('core/notices').createNotice('error', response && response.message ? response.message : (i18n.error_disconnecting || 'Error disconnecting account.'), { isDismissible: true });
      }
    } catch (e) {
      setDisconnecting(false);
      wp.data.dispatch('core/notices').createNotice('error', i18n.error_disconnecting || 'Error disconnecting account.', { isDismissible: true });
    }
  };

  return {
    isConfigured,
    isAuthenticated,
    authUrl,
    savedAppId,
    username,
    disconnecting,
    isLoading,
    checkAuthStatus,
    handleConnectInstagram,
    handleDisconnect,
  };
} 