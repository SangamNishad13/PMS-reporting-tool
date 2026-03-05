import React, { useState, useEffect, useMemo } from 'react';
import useIssuesStore from '../../store/issuesStore';
import IssueRow from './IssueRow';
import Filters from '../Filters/Filters';
import Loader from '../Common/Loader';
import Button from '../Common/Button';

const IssueTable = ({ projectId }) => {
  const { issues, loading, error, fetchIssues, selectedPageId, issueStatuses, fetchIssueStatuses } = useIssuesStore();
  const [expandedRows, setExpandedRows] = useState(new Set());
  const [filters, setFilters] = useState({
    search: '',
    status: '',
    severity: '',
    priority: '',
  });

  useEffect(() => {
    if (projectId) {
      fetchIssues(projectId, selectedPageId);
      fetchIssueStatuses(projectId);
    }
  }, [projectId, selectedPageId, fetchIssues, fetchIssueStatuses]);

  // Filter issues based on filters
  const filteredIssues = useMemo(() => {
    let filtered = [...issues];

    // Search filter
    if (filters.search) {
      const searchLower = filters.search.toLowerCase();
      filtered = filtered.filter(issue =>
        issue.title?.toLowerCase().includes(searchLower) ||
        issue.issue_key?.toLowerCase().includes(searchLower) ||
        issue.description?.toLowerCase().includes(searchLower)
      );
    }

    // Status filter
    if (filters.status) {
      filtered = filtered.filter(issue => issue.status_id == filters.status);
    }

    // Severity filter
    if (filters.severity) {
      filtered = filtered.filter(issue => {
        const severity = Array.isArray(issue.severity) ? issue.severity[0] : issue.severity;
        return severity === filters.severity;
      });
    }

    // Priority filter
    if (filters.priority) {
      filtered = filtered.filter(issue => {
        const priority = Array.isArray(issue.priority) ? issue.priority[0] : issue.priority;
        return priority === filters.priority;
      });
    }

    return filtered;
  }, [issues, filters]);

  const toggleRow = (issueId) => {
    const newExpanded = new Set(expandedRows);
    if (newExpanded.has(issueId)) {
      newExpanded.delete(issueId);
    } else {
      newExpanded.add(issueId);
    }
    setExpandedRows(newExpanded);
  };

  if (loading) {
    return <Loader text="Loading issues..." />;
  }

  if (error) {
    return (
      <div className="alert alert-danger">
        <i className="fas fa-exclamation-triangle me-2"></i>
        Error: {error}
      </div>
    );
  }

  if (!issues || issues.length === 0) {
    return (
      <div className="text-center py-5">
        <i className="fas fa-inbox fa-3x text-muted mb-3"></i>
        <p className="text-muted">No issues found</p>
        <Button 
          variant="primary" 
          icon="fas fa-plus"
          onClick={() => {/* TODO: Open create modal */}}
        >
          Create First Issue
        </Button>
      </div>
    );
  }

  return (
    <>
      {/* Filters */}
      <Filters 
        onFilterChange={setFilters} 
        issueStatuses={issueStatuses}
      />

      {/* Results count */}
      <div className="mb-2">
        <small className="text-muted">
          Showing {filteredIssues.length} of {issues.length} issues
        </small>
      </div>

      {/* Table */}
      {filteredIssues.length === 0 ? (
        <div className="alert alert-info">
          <i className="fas fa-info-circle me-2"></i>
          No issues match your filters
        </div>
      ) : (
        <div className="table-responsive">
          <table className="table table-hover">
            <thead>
              <tr>
                <th style={{ width: '30px' }}></th>
                <th>Issue Key</th>
                <th>Title</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Severity</th>
                <th>Reporter</th>
                <th>Updated</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredIssues.map((issue) => (
                <IssueRow
                  key={issue.id}
                  issue={issue}
                  projectId={projectId}
                  isExpanded={expandedRows.has(issue.id)}
                  onToggle={() => toggleRow(issue.id)}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
};

export default IssueTable;
