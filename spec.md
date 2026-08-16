

SIE::model('model_name')->encode(...) 
SIE::model('model_name')->score(...) 
SIE::model('model_name')->extract(...) 
SIE::model('model_name')->generate(...) 
SIE::model('model_name')->info(...) 

SIE::model('model_name') return a PendingAction on which we can eventually chain calls to set the pool/gpu options and other.


SIE::models()

SIE::warmup($models=[])

SIE::connector() // marked Internal to expose the Saloon Connector after client is initialized from config