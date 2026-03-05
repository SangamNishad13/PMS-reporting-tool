import { useState, useEffect } from 'react'
import './App.css'

function App() {
  const [config, setConfig] = useState(null);

  useEffect(() => {
    // Get config from PHP
    if (window.APP_CONFIG) {
      setConfig(window.APP_CONFIG);
    }
  }, []);

  if (!config) {
    return (
      <div className="container mt-5">
        <div className="text-center">
          <div className="spinner-border text-primary" role="status">
            <span className="visually-hidden">Loading...</span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="container-fluid mt-4">
      <div className="row">
        <div className="col-12">
          <div className="card shadow-sm">
            <div className="card-header bg-primary text-white">
              <h4 className="mb-0">
                <i className="fas fa-bug me-2"></i>
                Issues Management - React Version
              </h4>
            </div>
            <div className="card-body">
              <div className="alert alert-success">
                <h5><i className="fas fa-check-circle me-2"></i>React App Successfully Loaded!</h5>
                <hr />
                <p className="mb-2"><strong>Project:</strong> {config.projectTitle}</p>
                <p className="mb-2"><strong>Project ID:</strong> {config.projectId}</p>
                <p className="mb-2"><strong>Project Type:</strong> {config.projectType}</p>
                <p className="mb-2"><strong>User:</strong> {config.userName}</p>
                <p className="mb-2"><strong>Role:</strong> {config.userRole}</p>
                <p className="mb-0"><strong>API Base:</strong> {config.apiBase}</p>
              </div>

              <div className="row mt-4">
                <div className="col-md-4">
                  <div className="card bg-light">
                    <div className="card-body text-center">
                      <i className="fas fa-list fa-3x text-primary mb-3"></i>
                      <h5>All Issues</h5>
                      <p className="text-muted">View all issues across pages</p>
                      <button className="btn btn-primary btn-sm">Coming Soon</button>
                    </div>
                  </div>
                </div>
                <div className="col-md-4">
                  <div className="card bg-light">
                    <div className="card-body text-center">
                      <i className="fas fa-file-alt fa-3x text-success mb-3"></i>
                      <h5>Issues by Pages</h5>
                      <p className="text-muted">View issues organized by pages</p>
                      <button className="btn btn-success btn-sm">Coming Soon</button>
                    </div>
                  </div>
                </div>
                <div className="col-md-4">
                  <div className="card bg-light">
                    <div className="card-body text-center">
                      <i className="fas fa-layer-group fa-3x text-warning mb-3"></i>
                      <h5>Common Issues</h5>
                      <p className="text-muted">View common issues</p>
                      <button className="btn btn-warning btn-sm">Coming Soon</button>
                    </div>
                  </div>
                </div>
              </div>

              <div className="mt-4">
                <h5>Next Steps:</h5>
                <ol>
                  <li>Build the issue table component</li>
                  <li>Create issue modal for create/edit</li>
                  <li>Add comments functionality</li>
                  <li>Implement filters and search</li>
                  <li>Add real-time updates</li>
                </ol>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default App
