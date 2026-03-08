import axios from 'axios';

// Get base URL from window or use default
const getBaseUrl = () => {
  if (typeof window !== 'undefined' && window.APP_CONFIG?.baseUrl) {
    return window.APP_CONFIG.baseUrl;
  }
  return '/PMS'; // Default fallback
};

const api = axios.create({
  baseURL: getBaseUrl(),
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
  },
  withCredentials: true,
});

// Request interceptor
api.interceptors.request.use(
  (config) => {
    // Convert data to URLSearchParams for PHP compatibility
    if (config.data && config.headers['Content-Type'] === 'application/x-www-form-urlencoded') {
      const params = new URLSearchParams();
      Object.keys(config.data).forEach(key => {
        const value = config.data[key];
        if (Array.isArray(value)) {
          params.append(key, value.join(','));
        } else if (typeof value === 'object' && value !== null) {
          params.append(key, JSON.stringify(value));
        } else {
          params.append(key, value);
        }
      });
      config.data = params;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Response interceptor
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      window.location.href = '/PMS/modules/auth/login.php';
    }
    return Promise.reject(error);
  }
);

export default api;
