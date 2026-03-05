import { useState, useEffect } from 'react';
import issuesApi from '../../services/issuesApi';
import { formatDate } from '../../utils/formatters';
import Button from '../Common/Button';
import './Comments.css';

const Comments = ({ issueId }) => {
  const [comments, setComments] = useState([]);
  const [newComment, setNewComment] = useState('');
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (issueId) {
      loadComments();
    }
  }, [issueId]);

  const loadComments = async () => {
    setLoading(true);
    try {
      const response = await issuesApi.getComments(issueId);
      setComments(response.comments || []);
    } catch (error) {
      console.error('Failed to load comments:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    
    if (!newComment.trim()) {
      return;
    }

    setSubmitting(true);
    try {
      await issuesApi.addComment(issueId, { comment: newComment });
      setNewComment('');
      await loadComments(); // Reload comments
    } catch (error) {
      console.error('Failed to add comment:', error);
      alert('Failed to add comment. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="text-center py-4">
        <div className="spinner-border spinner-border-sm text-primary" role="status">
          <span className="visually-hidden">Loading comments...</span>
        </div>
      </div>
    );
  }

  return (
    <div className="comments-section">
      <h6 className="mb-3">
        <i className="fas fa-comments me-2"></i>
        Comments ({comments.length})
      </h6>

      {/* Comment List */}
      <div className="comments-list mb-3">
        {comments.length === 0 ? (
          <div className="alert alert-info">
            <i className="fas fa-info-circle me-2"></i>
            No comments yet. Be the first to comment!
          </div>
        ) : (
          comments.map(comment => (
            <div key={comment.id} className="comment-item">
              <div className="comment-header">
                <strong>{comment.user_name}</strong>
                <small className="text-muted ms-2">{formatDate(comment.created_at)}</small>
              </div>
              <div className="comment-body" dangerouslySetInnerHTML={{ __html: comment.comment }} />
            </div>
          ))
        )}
      </div>

      {/* Add Comment Form */}
      <form onSubmit={handleSubmit}>
        <div className="mb-2">
          <textarea
            className="form-control"
            rows="3"
            value={newComment}
            onChange={(e) => setNewComment(e.target.value)}
            placeholder="Add a comment..."
            disabled={submitting}
          />
        </div>
        <div className="text-end">
          <Button
            type="submit"
            variant="primary"
            size="sm"
            icon="fas fa-paper-plane"
            disabled={submitting || !newComment.trim()}
          >
            {submitting ? 'Posting...' : 'Post Comment'}
          </Button>
        </div>
      </form>
    </div>
  );
};

export default Comments;
