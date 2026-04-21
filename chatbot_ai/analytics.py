import pickle
import numpy as np
import json
from collections import Counter
from sklearn.ensemble import RandomForestClassifier
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.preprocessing import LabelEncoder
import re

# ── Sentiment keyword lists (built from scratch) ──────────────────────────────

POSITIVE_WORDS = [
    'good', 'great', 'excellent', 'amazing', 'wonderful', 'fantastic',
    'perfect', 'best', 'love', 'happy', 'satisfied', 'outstanding',
    'reliable', 'quality', 'recommend', 'helpful', 'fast', 'efficient',
    'professional', 'trustworthy', 'fresh', 'clean', 'safe', 'fair',
    'affordable', 'consistent', 'punctual', 'responsive', 'honest',
    'bien', 'bon', 'parfait', 'super', 'formidable',
    'rapide', 'fiable', 'propre', 'qualite', 'recommande'
]

NEGATIVE_WORDS = [
    'bad', 'terrible', 'awful', 'horrible', 'worst', 'poor', 'slow',
    'unreliable', 'dirty', 'damaged', 'broken', 'late', 'expensive',
    'rude', 'dishonest', 'wrong', 'missing', 'defective', 'complaint',
    'disappointed', 'unacceptable', 'failed', 'useless', 'waste',
    'problem', 'issue', 'delay', 'overpriced', 'fake', 'fraud',
    'damn', 'shit', 'fuck', 'crap', 'disgusting', 'pathetic',
    'mauvais', 'nul', 'retard', 'cher', 'arnaque',
    'probleme', 'defaut', 'casse', 'sale', 'lent', 'incompetent'
]

# ── Complaint topic keywords ───────────────────────────────────────────────────

COMPLAINT_TOPICS = {
    'Quality Issues':     ['quality', 'defective', 'broken', 'damaged', 'fake',
                           'poor quality', 'bad quality', 'not good', 'inferior',
                           'low quality', 'defaut', 'mauvaise qualite', 'shit',
                           'crap', 'disgusting', 'pathetic', 'terrible', 'awful'],
    'Delivery Problems':  ['late', 'delay', 'slow', 'never arrived', 'missing',
                           'not delivered', 'wrong item', 'retard', 'livraison'],
    'Price Complaints':   ['expensive', 'overpriced', 'too much', 'costly',
                           'not worth', 'cher', 'trop cher', 'prix'],
    'Service Issues':     ['rude', 'unhelpful', 'no response', 'ignored',
                           'bad service', 'poor service', 'incompetent',
                           'mauvais service', 'impoli', 'damn', 'worst'],
    'Product Problems':   ['wrong product', 'not as described', 'misleading',
                           'different', 'not what', 'produit', 'article'],
    'Communication':      ['no communication', 'no reply', 'unresponsive',
                           'no answer', 'ignored', 'contact', 'communication'],
    'Hygiene & Safety':   ['dirty', 'unsafe', 'contaminated', 'expired',
                           'unhygienic', 'sale', 'perime', 'contamination'],
}


class SupplierAnalyticsAI:
    """
    AI engine for supplier rating analytics.

    Uses:
    - TF-IDF for keyword/complaint extraction
    - Rule-based sentiment scoring (built from scratch)
    - Random Forest for supplier health classification
    """

    def __init__(self):
        self.health_model   = None
        self.health_encoder = LabelEncoder()
        self._train_health_classifier()

    # ── Train Random Forest health classifier ─────────────────────────────────

    def _train_health_classifier(self):
        """
        Train a Random Forest to classify supplier health based on:
        - avg_stars        (0-5)
        - neg_pct          (0.0-1.0)
        - review_count     (integer)
        - complaint_count  (integer)

        Labels: Excellent / Good / Needs Improvement / Poor

        Boundaries:
          Excellent        → avg >= 4.5, neg_pct < 0.10
          Good             → avg 3.5–4.4, neg_pct 0.10–0.25
          Needs Improvement→ avg 2.5–3.4, neg_pct 0.25–0.55
          Poor             → avg < 2.5,   neg_pct > 0.55
        """

        # [avg_stars, neg_pct, review_count, complaint_count]
        X_train = np.array([
            # ── Excellent: avg >= 4.5, neg_pct < 0.10 ────────────────────
            [4.8, 0.02, 50, 0], [4.9, 0.01, 30, 0], [5.0, 0.00, 20, 0],
            [4.7, 0.05, 40, 0], [4.6, 0.03, 25, 0], [4.8, 0.02, 60, 0],
            [4.9, 0.01, 15, 0], [4.7, 0.04, 35, 1], [4.5, 0.08, 10, 0],
            [4.6, 0.06, 45, 0], [5.0, 0.00, 5,  0], [4.8, 0.03, 100, 0],

            # ── Good: avg 3.5–4.4, neg_pct 0.10–0.25 ─────────────────────
            [4.0, 0.10, 40, 1], [4.2, 0.08, 30, 1], [4.3, 0.07, 20, 1],
            [3.9, 0.12, 50, 2], [4.1, 0.09, 25, 1], [4.4, 0.06, 45, 1],
            [3.8, 0.15, 30, 2], [4.0, 0.10, 55, 2], [3.5, 0.20, 15, 1],
            [3.7, 0.18, 20, 2], [4.2, 0.11, 8,  1], [3.6, 0.22, 12, 1],

            # ── Needs Improvement: avg 2.5–3.4, neg_pct 0.25–0.55 ────────
            [3.0, 0.30, 30, 3], [3.2, 0.25, 20, 3], [2.9, 0.35, 40, 4],
            [3.1, 0.28, 25, 3], [3.3, 0.22, 35, 3], [2.8, 0.40, 15, 4],
            [3.0, 0.32, 50, 4], [3.4, 0.20, 30, 3], [2.5, 0.45, 10, 3],
            [2.7, 0.38, 18, 4], [3.2, 0.30, 6,  2], [2.6, 0.42, 22, 3],

            # ── Poor: avg < 2.5, neg_pct > 0.55 ──────────────────────────
            [1.5, 0.80, 20, 5], [1.8, 0.75, 30, 5], [2.0, 0.70, 25, 6],
            [1.2, 0.90, 10, 6], [2.2, 0.65, 40, 5], [1.0, 0.95, 15, 7],
            [1.9, 0.72, 22, 5], [2.1, 0.68, 18, 6], [2.3, 0.60, 8,  4],
            [1.5, 0.85, 6,  5], [2.0, 0.67, 3,  4], [1.7, 0.78, 12, 6],

            # ── Edge cases: very few reviews ──────────────────────────────
            # Bad stars + few reviews → Poor
            [1.0, 1.00, 1, 1], [2.0, 0.50, 2, 1], [1.5, 1.00, 2, 2],
            [2.3, 0.67, 3, 2], [2.0, 0.60, 4, 2], [1.8, 0.75, 5, 3],

            # Good stars + few reviews → Good (not Excellent — insufficient data)
            [5.0, 0.00, 1, 0], [4.0, 0.00, 2, 0], [4.5, 0.00, 1, 0],
            [4.8, 0.00, 2, 0], [4.3, 0.00, 3, 0], [4.6, 0.00, 4, 0],

            # Mixed stars + few reviews → Needs Improvement
            [2.5, 0.50, 2, 1], [3.0, 0.33, 3, 1], [2.0, 0.67, 3, 2],
            [2.3, 0.50, 4, 1], [2.8, 0.40, 5, 2], [3.1, 0.33, 6, 2],

            # Medium stars but many complaints → Needs Improvement
            [3.5, 0.20, 20, 5], [3.8, 0.15, 15, 4], [4.0, 0.10, 10, 5],

            # High neg_pct even with medium stars → Poor
            [2.4, 0.70, 10, 4], [2.0, 0.80, 5,  3], [2.2, 0.75, 8,  4],
        ])

        y_labels = (
            ['Excellent']          * 12 +
            ['Good']               * 12 +
            ['Needs Improvement']  * 12 +
            ['Poor']               * 12 +
            # Edge cases
            ['Poor']               * 6  +   # bad stars + few reviews
            ['Good']               * 6  +   # good stars + few reviews → Good not Excellent
            ['Needs Improvement']  * 6  +   # mixed stars + few reviews
            ['Needs Improvement']  * 3  +   # medium stars + many complaints
            ['Poor']               * 3       # high neg_pct
        )

        y_train = self.health_encoder.fit_transform(y_labels)

        self.health_model = RandomForestClassifier(
            n_estimators=300,        # 300 decision trees for stability
            max_depth=8,             # limit depth to prevent overfitting
            min_samples_leaf=2,      # each leaf needs at least 2 samples
            random_state=42,
            n_jobs=-1,
            class_weight='balanced'  # handle class imbalance
        )
        self.health_model.fit(X_train, y_train)

    # ── Sentiment scoring ─────────────────────────────────────────────────────

    def _score_sentiment(self, text: str) -> dict:
        """
        Score sentiment of a comment using keyword matching.
        Also considers star rating context for short/vague comments.
        Returns: {'score': float -1 to 1, 'label': str, 'positive': int, 'negative': int}
        """
        if not text:
            return {'score': 0.0, 'label': 'Neutral', 'positive': 0, 'negative': 0}

        words    = re.findall(r'\b\w+\b', text.lower())
        pos_hits = sum(1 for w in words if w in POSITIVE_WORDS)
        neg_hits = sum(1 for w in words if w in NEGATIVE_WORDS)
        total    = pos_hits + neg_hits

        if total == 0:
            score = 0.0
        else:
            score = (pos_hits - neg_hits) / total

        if score > 0.2:
            label = 'Positive'
        elif score < -0.2:
            label = 'Negative'
        else:
            label = 'Neutral'

        return {
            'score':    round(score, 3),
            'label':    label,
            'positive': pos_hits,
            'negative': neg_hits,
        }

    # ── Star-aware sentiment scoring ──────────────────────────────────────────

    def _score_sentiment_with_stars(self, text: str, stars: int) -> dict:
        """
        Enhanced sentiment that also uses the star rating as a signal.
        If a comment has no strong keywords, stars decide the sentiment.
        """
        base = self._score_sentiment(text)

        # If no keyword hits, use stars as fallback
        if base['positive'] == 0 and base['negative'] == 0:
            if stars >= 4:
                return {**base, 'label': 'Positive', 'score': 0.5}
            elif stars <= 2:
                return {**base, 'label': 'Negative', 'score': -0.5}

        return base

    # ── Complaint topic detection ─────────────────────────────────────────────

    def _detect_complaint_topics(self, comments: list) -> dict:
        """
        Scan all negative/low-star comments and count complaint topics.
        Returns topic counts sorted by frequency.
        """
        topic_counts = Counter()
        full_text    = ' '.join(comments).lower()

        for topic, keywords in COMPLAINT_TOPICS.items():
            for keyword in keywords:
                if keyword in full_text:
                    topic_counts[topic] += full_text.count(keyword)

        return dict(topic_counts.most_common())

    # ── TF-IDF keyword extraction ─────────────────────────────────────────────

    def _extract_keywords(self, comments: list, top_n: int = 8) -> list:
        """
        Use TF-IDF to extract the most significant words from comments.
        Works with as few as 1 comment by lowering min_df.
        """
        clean = [c for c in comments if c and c.strip()]
        if not clean:
            return []

        # With only 1 document, TF-IDF is just TF — still useful
        min_df = 1 if len(clean) < 3 else 2

        vectorizer = TfidfVectorizer(
            ngram_range=(1, 2),
            stop_words='english',
            max_features=200,
            min_df=min_df
        )

        try:
            tfidf_matrix = vectorizer.fit_transform(clean)
            scores       = tfidf_matrix.sum(axis=0).A1
            vocab        = vectorizer.get_feature_names_out()
            ranked       = sorted(
                zip(vocab, scores), key=lambda x: x[1], reverse=True
            )
            return [word for word, score in ranked[:top_n]]
        except Exception:
            return []

    # ── Supplier health classification ────────────────────────────────────────

    def _classify_health(self, avg_stars: float, neg_pct: float,
                          review_count: int, complaint_count: int) -> dict:
        """
        Use trained Random Forest to classify supplier health.
        Applies hard rules first to override the model for clear-cut cases.
        """
        # ── Hard rules override the model for obvious cases ───────────────────
        if avg_stars >= 4.5 and neg_pct < 0.10 and review_count >= 5:
            return {'label': 'Excellent', 'confidence': 0.95}

        if avg_stars < 2.0 and neg_pct > 0.60:
            return {'label': 'Poor', 'confidence': 0.95}

        if avg_stars >= 2.0 and avg_stars < 2.5 and neg_pct >= 0.50:
            return {'label': 'Poor', 'confidence': 0.90}

        if avg_stars < 2.5 and review_count <= 3:
            return {'label': 'Poor', 'confidence': 0.85}

        if avg_stars >= 4.5 and review_count < 5:
            return {'label': 'Good', 'confidence': 0.80}

        # ── Random Forest for everything else ─────────────────────────────────
        if self.health_model is None:
            return {'label': 'Unknown', 'confidence': 0.0}

        X          = np.array([[avg_stars, neg_pct, review_count, complaint_count]])
        pred_index = self.health_model.predict(X)[0]
        proba      = self.health_model.predict_proba(X)[0]
        confidence = float(np.max(proba))
        label      = self.health_encoder.inverse_transform([pred_index])[0]

        return {'label': label, 'confidence': round(confidence, 3)}

    # ── Generate recommendation text ──────────────────────────────────────────

    def _generate_recommendation(self, health: str, topics: dict,
                                  avg_stars: float) -> str:
        """
        Generate a human-readable recommendation based on health + topics.
        """
        top_topic = list(topics.keys())[0] if topics else None

        if health == 'Excellent':
            return (
                f'This supplier is performing excellently with an average of '
                f'{avg_stars:.1f} stars. Continue the current partnership.'
            )
        elif health == 'Good':
            msg = f'This supplier is performing well ({avg_stars:.1f} stars).'
            if top_topic:
                msg += f' Monitor reports related to: {top_topic}.'
            return msg
        elif health == 'Needs Improvement':
            msg = f'This supplier needs attention ({avg_stars:.1f} stars).'
            if top_topic:
                msg += (
                    f' The most reported issue is: {top_topic}. '
                    f'Consider discussing this with the supplier.'
                )
            return msg
        else:  # Poor
            msg = (
                f'⚠️ This supplier is underperforming ({avg_stars:.1f} stars). '
                f'Immediate review recommended.'
            )
            if top_topic:
                msg += f' Main complaint: {top_topic}.'
            return msg

    # ── Main analysis method ──────────────────────────────────────────────────

    def analyze_supplier(self, supplier_data: dict) -> dict:
        """
        Full analysis for one supplier.

        supplier_data = {
            'supplier_id':   int,
            'supplier_name': str,
            'ratings': [
                {'stars': int, 'comment': str, 'created_at': str},
                ...
            ]
        }
        """
        ratings = supplier_data.get('ratings', [])
        total   = len(ratings)

        if total == 0:
            return {
                'supplier_id':         supplier_data['supplier_id'],
                'supplier_name':       supplier_data['supplier_name'],
                'total_reviews':       0,
                'avg_stars':           0,
                'health':              {'label': 'No Data', 'confidence': 0},
                'sentiment_breakdown': {'Positive': 0, 'Neutral': 0, 'Negative': 0},
                'top_complaints':      {},
                'top_keywords':        [],
                'recommendation':      'No reviews yet for this supplier.',
                'star_distribution':   {1: 0, 2: 0, 3: 0, 4: 0, 5: 0},
            }

        # ── Basic stats ───────────────────────────────────────────────────────
        stars_list = [r['stars'] for r in ratings]
        avg_stars  = round(sum(stars_list) / total, 2)

        star_dist = {1: 0, 2: 0, 3: 0, 4: 0, 5: 0}
        for s in stars_list:
            if s in star_dist:
                star_dist[s] += 1

        # ── Sentiment analysis (star-aware) ───────────────────────────────────
        sentiments   = {'Positive': 0, 'Neutral': 0, 'Negative': 0}
        all_comments = []

        for r in ratings:
            comment = r.get('comment') or ''
            stars   = r.get('stars', 3)
            all_comments.append(comment)
            sent = self._score_sentiment_with_stars(comment, stars)
            sentiments[sent['label']] += 1

        neg_pct = round(sentiments['Negative'] / total, 3)

        # ── Complaint topics (from low-star comments) ─────────────────────────
        # Include 1-3 star comments, not just 1-2
        negative_comments = [
            r.get('comment', '') for r in ratings
            if r['stars'] <= 3 and r.get('comment')
        ]
        topics = self._detect_complaint_topics(negative_comments)

        # ── TF-IDF keywords from all non-empty comments ───────────────────────
        keywords = self._extract_keywords(
            [c for c in all_comments if c.strip()]
        )

        # ── Random Forest health classification ───────────────────────────────
        health = self._classify_health(
            avg_stars      = avg_stars,
            neg_pct        = neg_pct,
            review_count   = total,
            complaint_count= len(topics)
        )

        # ── Recommendation ────────────────────────────────────────────────────
        recommendation = self._generate_recommendation(
            health['label'], topics, avg_stars
        )

        return {
            'supplier_id':         supplier_data['supplier_id'],
            'supplier_name':       supplier_data['supplier_name'],
            'total_reviews':       total,
            'avg_stars':           avg_stars,
            'health':              health,
            'sentiment_breakdown': sentiments,
            'neg_pct':             neg_pct,
            'top_complaints':      topics,
            'top_keywords':        keywords,
            'recommendation':      recommendation,
            'star_distribution':   star_dist,
        }