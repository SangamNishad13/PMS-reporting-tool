import React, { useState, useEffect } from 'react';
import useIssuesStore from '../../store/issuesStore';
import IssueRow from './IssueRow';
import Loader from '../Common/Loader';
import Button from '../Common/Button';

const IssueTable = ({ projectId }) => {
  const { issues, loading, error, fetchIssues, selectedPageId } = useIssuesStore();
  const [expandedRows, setExpandedRows] = useState(new Set());

  useEffect(() => {
    if (projectId) {
      fetchIssues(projectId, selectedPageId);
    }
  }, [projectId, selectedPageId, fetchIssues]);

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
          {issues.map((issue) => (
            <IssueRow
              key={issue.id}
              issue={issue}
              isExpanded={expandedRows.has(issue.id)}
              onToggle={() => toggleRow(issue.id)}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
};

export default IssueTable;
