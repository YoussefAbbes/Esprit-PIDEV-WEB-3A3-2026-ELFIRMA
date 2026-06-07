import { Component } from 'react';

export default class ErrorBoundary extends Component {
  state = { error: null };

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    console.error('[ErrorBoundary]', error, info);
  }

  render() {
    if (!this.state.error) return this.props.children;

    return (
      <div className="content">
        <div className="panel" style={{ borderColor: 'rgba(239,68,68,0.3)' }}>
          <div className="panel-head">
            <span className="panel-title" style={{ color: 'var(--danger)' }}>
              ⚠ Render error
            </span>
            <button
              className="panel-action"
              style={{ background: 'none', border: 'none' }}
              onClick={() => this.setState({ error: null })}
            >
              Retry →
            </button>
          </div>
          <div className="panel-body">
            <pre style={{
              fontSize: '0.78rem',
              color: 'var(--muted)',
              whiteSpace: 'pre-wrap',
              fontFamily: 'monospace',
              lineHeight: 1.5,
            }}>
              {String(this.state.error?.stack || this.state.error)}
            </pre>
          </div>
        </div>
      </div>
    );
  }
}
