from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from transformers import pipeline
import uvicorn
import logging

# Set up logging to see what's happening
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

app = FastAPI()

# Add CORS middleware to allow browser requests
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Allow all origins for development
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# ============================================
# OPTION 1: Multilingual Model (RECOMMENDED)
# Supports: Bisaya, Tagalog, English, +20 languages
# ============================================
logger.info("Loading MULTILINGUAL sentiment analysis model...")
logger.info("This model supports: Bisaya, Tagalog, English, Spanish, French, etc.")

classifier = pipeline("text-classification", 
                     model="tabularisai/multilingual-sentiment-analysis")

# ============================================
# OPTION 2: Filipino-Specific Model (FASTER)
# Uncomment this and comment the one above if you want faster inference
# ============================================
# logger.info("Loading FILIPINO sentiment analysis model...")
# classifier = pipeline("text-classification", 
#                      model="jpcurada/nlp-cdk-filipino-sentiment-analysis")

# ============================================
# OPTION 3: RoBERTa Tagalog Model (ALTERNATIVE)
# Uncomment this and comment the ones above if you want a Tagalog-specific model
# ============================================
# logger.info("Loading TAGALOG sentiment analysis model...")
# classifier = pipeline("text-classification", 
#                      model="dost-asti/RoBERTa-tl-sentiment-analysis")

class TextInput(BaseModel):
    text: str

@app.get("/")
async def root():
    return {
        "message": "Multilingual Sentiment Analysis Service",
        "supported_languages": "Bisaya (Cebuano), Tagalog, English, and 20+ other languages",
        "model": classifier.model.config._name_or_path if classifier else "Not loaded"
    }

@app.get("/health")
async def health_check():
    return {
        "status": "healthy",
        "model": classifier.model.config._name_or_path if classifier else "Not loaded",
        "supported_labels": classifier.model.config.id2label if classifier else {}
    }

@app.post("/analyze-sentiment")
async def analyze_sentiment(input_data: TextInput):
    result = classifier(input_data.text)[0]
    
    # The multilingual model returns labels like:
    # "Very Negative", "Negative", "Neutral", "Positive", "Very Positive"
    return {
        "sentiment": result['label'],
        "confidence": result['score'],
        "text": input_data.text,
        "model": classifier.model.config._name_or_path
    }

if __name__ == "__main__":
    uvicorn.run(app, host="0.0.0.0", port=8000)