import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.jsx'
import useIssuesStore from './store/issuesStore'
import { issuesApi } from './services/issuesApi'

// Expose store and API to window for PHP integration
window.issuesStore = useIssuesStore;
window.issuesApi = issuesApi;

const rootElement = document.getElementById('issues-app-root');
if (rootElement) {
  createRoot(rootElement).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
