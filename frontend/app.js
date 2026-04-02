import { createApp, reactive } from 'https://unpkg.com/vue@3/dist/vue.esm-browser.prod.js';

createApp({
  setup() {
    const state = reactive({
      currentTab: 'presence',
      apiBase: '',
      busy: false,
      msg: '',
      msgType: '', // 'ok' | 'err'
      user: null,
      form: {
        nom: '',
        prenom: '',
        email: '',
        password: '',
      },
    });

    function setMessage(type, text) {
      state.msgType = type;
      state.msg = text;
    }

    function clearMessage() {
      state.msgType = '';
      state.msg = '';
    }

    function endpointUrl(relativePath) {
      // Default: endpoints live at project root, while frontend is in /frontend
      // -> use ../php/create_account.php when served from /frontend
      const base = state.apiBase?.trim();
      if (base) {
        const cleaned = relativePath.replace(/^\.\.\//, '').replace(/^\/+/, '');
        return base.replace(/\/+$/, '') + '/' + cleaned;
      }
      return relativePath;
    }

    function loadFromStorage() {
      try {
        const raw = localStorage.getItem('edc.user');
        if (raw) state.user = JSON.parse(raw);
      } catch {
        // ignore
      }
    }

    function saveToStorage(user) {
      try {
        localStorage.setItem('edc.user', JSON.stringify(user));
      } catch {
        // ignore
      }
    }

    function clearStorage() {
      try {
        localStorage.removeItem('edc.user');
      } catch {
        // ignore
      }
    }

    async function postJson(url, body) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(body),
      });

      let data = null;
      try {
        data = await res.json();
      } catch {
        data = null;
      }

      return { ok: res.ok, status: res.status, data };
    }

    async function refreshSession() {
      try {
        const url = endpointUrl('../php/me.php');
        const res = await fetch(url, { method: 'GET', credentials: 'include' });
        const data = await res.json().catch(() => null);
        if (res.ok && data?.authenticated && data?.user) {
          state.user = {
            userId: data.user.id,
            nom: data.user.nom,
            prenom: data.user.prenom,
            email: data.user.email,
            role: data.user.role,
            folderName: data.user.folderName,
            createdAt: data.user.createdAt,
          };
          saveToStorage(state.user);

          if (state.currentTab === 'compte') state.currentTab = 'presence';
        } else {
          // session perdue
          state.user = null;
          clearStorage();
        }
      } catch {
        // ignore (offline / not served via PHP)
      }
    }

    function goPanel() {
      const role = state.user?.role;
      if (!role) return;
      if (role === 'admin') window.location.href = './users/admin/index.php';
      else if (role === 'teacher') window.location.href = './users/teacher/index.php';
      else window.location.href = './users/student/index.php';
    }

    function goPresence() {
      window.location.href = './presence/presence.php';
    }

    async function createAccount() {
      clearMessage();
      state.busy = true;
      try {
        const payload = {
          nom: state.form.nom,
          prenom: state.form.prenom,
          email: state.form.email,
          password: state.form.password,
          role: 'student',
        };

        const url = endpointUrl('../php/create_account.php');
        const { ok, status, data } = await postJson(url, payload);

        if (!ok) {
          const err = data?.error || `HTTP ${status}`;
          const hint = status === 404 ? ' (API introuvable: tu sers bien le projet depuis la racine ?)' : '';
          setMessage('err', `Création échouée: ${err}${hint}`);
          return;
        }

        // Enchaîne sur une connexion logique locale
        const user = {
          userId: data.userId,
          nom: payload.nom,
          prenom: payload.prenom,
          email: payload.email,
          role: 'student',
          folderName: data.folderName,
          createdAt: data.createdAt,
        };
        state.user = user;
        saveToStorage(user);
        setMessage('ok', 'Compte créé et session enregistrée.');

        // sync session (cookie) if available
        await refreshSession();

        state.currentTab = 'presence';
      } catch (e) {
        setMessage('err', `Erreur: ${e?.message || e}`);
      } finally {
        state.busy = false;
      }
    }

    async function login() {
      clearMessage();
      state.busy = true;
      try {
        const payload = {
          email: state.form.email,
          password: state.form.password,
        };

        const url = endpointUrl('../php/login.php');
        const { ok, status, data } = await postJson(url, payload);

        if (!ok) {
          const err = data?.error || `HTTP ${status}`;
          const hint = status === 404 ? ' (API introuvable: tu sers bien le projet depuis la racine ?)' : '';
          setMessage('err', `Connexion échouée: ${err}${hint}`);
          return;
        }

        const user = {
          userId: data.userId,
          nom: data.nom,
          prenom: data.prenom,
          email: data.email,
          role: data.role,
          folderName: data.folderName,
          createdAt: data.createdAt,
        };
        state.user = user;
        saveToStorage(user);
        setMessage('ok', 'Connecté.');

        await refreshSession();

        state.currentTab = 'presence';
      } catch (e) {
        setMessage('err', `Erreur: ${e?.message || e}`);
      } finally {
        state.busy = false;
      }
    }

    async function logout() {
      state.busy = true;
      try {
        await postJson(endpointUrl('../php/logout.php'), {});
      } catch {
        // ignore
      } finally {
        state.user = null;
        clearStorage();
        setMessage('ok', 'Déconnecté.');
        state.currentTab = 'presence';
        state.busy = false;
      }
    }

    function setTab(tab) {
      state.currentTab = tab;
    }

    // Init: api base via query param (?api=http://localhost:8099)
    try {
      const params = new URLSearchParams(window.location.search);
      const api = params.get('api');
      if (api) state.apiBase = api;
    } catch {
      // ignore
    }
    loadFromStorage();
    refreshSession();

    return {
      state,
      setTab,
      createAccount,
      login,
      logout,
      goPanel,
      goPresence,
    };
  },
}).mount('#app');
