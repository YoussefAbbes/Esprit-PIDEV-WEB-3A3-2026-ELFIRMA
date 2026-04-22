import pickle
import numpy as np
import json
from collections import Counter
from sklearn.ensemble import RandomForestClassifier
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.preprocessing import LabelEncoder
import re

# ── Sentiment keyword lists ──────────────────────────────────────────────────

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
    Uses: TF-IDF, rule-based sentiment scoring, and Random Forest classification.
    """

    def __init__(self):
        self.health_model   = None
        self.health_encoder = LabelEncoder()
        self._train_health_classifier()

    def _train_health_classifier(self):
        """Train Random Forest classifier for supplier health"""
        
        X_train = np.array([
            # Excellent: avg >= 4.5, neg_pct < 0.10
            [4.8, 0.02, 50, 0], [4.9, 0.01, 30, 0], [5.0, 0.00, 20, 0],
            [4.7, 0.05, 40, 0], [4.6, 0.03, 25, 0], [4.8, 0.02, 60, 0],
            [4.9, 0.01, 15, 0], [4.7, 0.04, 35, 1], [4.5, 0.08, 10, 0],
            [4.6, 0.06, 45, 0], [5.0, 0.00, 5,  0], [4.8, 0.03, 100, 0],

            # Good: avg 3.5–4.4, neg_pct 0.10–0.25
            [4.0, 0.10, 40, 1], [4.2, 0.08, 30, 1], [4.3, 0.07, 20, 1],
            [3.9, 0.12, 50, 2], [4.1, 0.09, 25, 1], [4.4, 0.06, 45, 1],
            [3.8, 0.15, 30, 2], [4.0, 0.10, 55, 2], [3.5, 0.20, 15, 1],
            [3.7, 0.18, 20, 2], [4.2, 0.11, 8,  1], [3.6, 0.22, 12, 1],

            # Needs Improvement: avg 2.5–3.4, neg_pct 0.25–0.55
            [3.0, 0.30, 30, 3], [3.2, 0.25, 20, 3], [2.9, 0.35, 40, 4],
            [3.1, 0.28, 25, 3], [3.3, 0.22, 35, 3], [2.8, 0.40, 15, 4],
            [3.0, 0.32, 50, 4], [3.4, 0.20, 30, 3], [2.5, 0.45, 10, 3],
            [2.7, 0.38, 18, 4], [3.2, 0.30, 6,  2], [2.6, 0.42, 22, 3],

            # Poor: avg < 2.5, neg_pct > 0.55
            [1.5, 0.80, 20, 5], [1.8, 0.75, 30, 5], [2.0, 0.70, 25, 6],
            [1.2, 0.90, 10, 6], [2.2, 0.65, 40, 5], [1.0, 0.95, 15, 7],
            [1.9, 0.72, 22, 5], [2.1, 0.68, 18, 6], [2.3, 0.60, 8,  4],
            [1.5, 0.85, 6,  5], [2.0, 0.67, 3,  4], [1.7, 0.78, 12, 6],
        ])

        y_labels = (
            ['Excellent'] * 12 +
            ['Good'] * 12 +
            ['Needs Improvement'] * 12 +
            ['Poor'] * 12
        )

        y_train = self.health_encoder.fit_transform(y_labels)

        self.health_model = RandomForestClassifier(
            n_estimators=300, max_depth=8, min_samples_leaf=2,
            random_state=42, n_jobs=-1, class_weight='balanced'
        )
        self.health_model.fit(X_train, y_train)

    def _score_sentiment(self, text: str) -> dict:
        """Score sentiment using keyword matching"""
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

    def _score_sentiment_with_stars(self, text: str, stars: int) -> dict:
        """Enhanced sentiment that uses star rating as signal"""
        base = self._score_sentiment(text)

        if base['positive'] == 0 and base['negative'] == 0:
            if stars >= 4:
                return {**base, 'label': 'Positive', 'score': 0.5}
            elif stars <= 2:
                return {**base, 'label': 'Negative', 'score': -0.5}

        return base

    def _detect_complaint_topics(self, comments: list) -> dict:
        """Scan comments and count complaint topics"""
        topic_counts = Counter()
        full_text    = ' '.join(comments).lower()

        for topic, keywords in COMPLAINT_TOPICS.items():
            for keyword in keywords:
                if keyword in full_text:
                    topic_counts[topic] += full_text.count(keyword)

        return dict(topic_counts.most_common())

    def _extract_keywords(self, comments: list, top_n: int = 8) -> list:
        """Extract keywords using TF-IDF"""
        clean = [c for c in comments if c and c.strip()]
        if not clean:
            return []

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

    def _classify_health(self, avg_stars: float, neg_pct: float,
                          review_count: int, complaint_count: int) -> dict:
        """Classify supplier health using Random Forest"""
        
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

        if self.health_model is None:
            return {'label': 'Unknown', 'confidence': 0.0}

        X          = np.array([[avg_stars, neg_pct, review_count, complaint_count]])
        pred_index = self.health_model.predict(X)[0]
        proba      = self.health_model.predict_proba(X)[0]
        confidence = float(np.max(proba))
        label      = self.health_encoder.inverse_transform([pred_index])[0]

        return {'label': label, 'confidence': round(confidence, 3)}

    def _generate_recommendation(self, health: str, topics: dict,
                                  avg_stars: float) -> str:
        """Generate AI recommendation"""
        top_topic = list(topics.keys())[0] if topics else None

        if health == 'Excellent':
            return (
                f'Excellent supplier ({avg_stars:.1f}★). '
                f'Continue current partnership - highly reliable.'
            )
        elif health == 'Good':
            msg = f'Good supplier ({avg_stars:.1f}★).'
            if top_topic:
                msg += f' Monitor: {top_topic}.'
            return msg
        elif health == 'Needs Improvement':
            msg = f'Needs improvement ({avg_stars:.1f}★).'
            if top_topic:
                msg += f' Main issue: {top_topic}. Discuss with supplier.'
            return msg
        else:  # Poor
            msg = (
                f'Poor supplier ({avg_stars:.1f}★). '
                f'Immediate review required.'
            )
            if top_topic:
                msg += f' Main complaint: {top_topic}.'
            return msg

    def analyze_supplier(self, supplier_data: dict) -> dict:
        """Full AI analysis for one supplier"""
        ratings = supplier_data.get('ratings', [])
        total   = len(ratings)

        if total == 0:
            return {
                'supplier_id':         supplier_data['supplier_id'],
                'supplier_name':       supplier_data['supplier_name'],
                'total_reviews':       0,
                'avg_stars':           0,
                'health':              {'label': 'No Data', 'confidence': 0},
                'sentiment_breakdown': {'positive': 0, 'neutral': 0, 'negative': 0},
                'top_complaints':      {},
                'top_keywords':        [],
                'recommendation':      'No reviews yet.',
                'star_distribution':   {1: 0, 2: 0, 3: 0, 4: 0, 5: 0},
            }

        # ── Basic stats ───────────────────────────────────────────────────────
        stars_list = [r['stars'] for r in ratings]
        avg_stars  = round(sum(stars_list) / total, 2)

        star_dist = {1: 0, 2: 0, 3: 0, 4: 0, 5: 0}
        for s in stars_list:
            if s in star_dist:
                star_dist[s] += 1

        # ── Sentiment analysis ────────────────────────────────────────────────
        sentiments   = {'positive': 0, 'neutral': 0, 'negative': 0}
        all_comments = []

        for r in ratings:
            comment = r.get('comment') or ''
            stars   = r.get('stars', 3)
            all_comments.append(comment)
            sent = self._score_sentiment_with_stars(comment, stars)
            # Map Positive/Neutral/Negative to positive/neutral/negative
            sentiment_key = sent['label'].lower()
            if sentiment_key in sentiments:
                sentiments[sentiment_key] += 1

        neg_pct = round(sentiments['negative'] / total, 3) if total > 0 else 0

        # ── Complaint topics ─────────────────────────────────────────────────
        negative_comments = [
            r.get('comment', '') for r in ratings
            if r['stars'] <= 3 and r.get('comment')
        ]
        topics = self._detect_complaint_topics(negative_comments)

        # ── TF-IDF keywords ──────────────────────────────────────────────────
        keywords = self._extract_keywords(
            [c for c in all_comments if c.strip()]
        )

        # ── Health classification ────────────────────────────────────────────
        health = self._classify_health(
            avg_stars      = avg_stars,
            neg_pct        = neg_pct,
            review_count   = total,
            complaint_count= len(topics)
        )

        # ── Recommendation ───────────────────────────────────────────────────
        recommendation = self._generate_recommendation(
            health['label'], topics, avg_stars
        )

        return {
            'supplier_id':         supplier_data['supplier_id'],
            'supplier_name':       supplier_data['supplier_name'],
            'total_reviews':       total,
            'average_rating':      avg_stars,
            'health':              health,
            'sentiment_breakdown': sentiments,
            'neg_pct':             neg_pct,
            'top_complaints':      topics,
            'top_keywords':        keywords,
            'recommendation':      recommendation,
            'star_distribution':   star_dist,
        }
